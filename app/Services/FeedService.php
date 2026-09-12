<?php

namespace App\Services;

use App\Enums\FeedMessageKind;
use App\Enums\FeedRoomKind;
use App\Enums\FeedStamp;
use App\Models\FeedMessage;
use App\Models\FeedReaction;
use App\Models\FeedRoom;
use App\Models\FeedRoomMember;
use App\Models\FeedRoomRead;
use App\Models\FeelingEntry;
use App\Models\Household;
use App\Models\Profile;
use App\Models\Quote;
use App\Notifications\FeedMessagePosted;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The family feed — somewhere for the kids to talk.
 *
 * They have this app and no phones, no accounts and nowhere to say anything to
 * each other. This is the whole of that: rooms, messages, stamps, drawings,
 * shout-outs and reactions.
 *
 * ## Nothing here pays anything
 *
 * No points, no tickets, no streak, no badge counts a message. The house was
 * asked and chose full trust: no moderation queue, no approval step, no report
 * button and no quiet hours. What stands in for all of that is one rule —
 * {@see FeedRoom::readableBy()} — and a who-can-read line drawn under every
 * room name, always, so that nobody ever learns their audience afterwards.
 *
 * ## Feelings and gratitude are not messages
 *
 * They are never written into `feed_messages`. The landing screen renders them
 * from their own tables through their own privacy rules — see houseToday() and
 * gratitudeToday() below, both of which delegate rather than re-decide.
 */
class FeedService
{
    /** Long enough for a real message, short enough to stay one. */
    public const MAX_BODY = 700;

    /**
     * The reaction sheet. Six, because the point of a reaction is that it is
     * faster than typing, and a grid you have to read is not.
     *
     * @var array<int, string>
     */
    public const REACTIONS = ['👍', '❤️', '😂', '👏', '👀', '🔥'];

    /**
     * How many messages a room shows at once.
     *
     * There is no paging back. A room between five people who live together is
     * a conversation, not an archive, and infinite scroll on a phone a
     * six-year-old is holding is a way to lose the composer.
     */
    public const PER_ROOM = 60;

    /**
     * The household roster, memoised for the life of one request — every room
     * in the list asks for it, and it is the same five people each time.
     *
     * @var array<int, Collection<int, Profile>>
     */
    private array $roster = [];

    public function __construct(private FeedDrawings $drawings) {}

    /*
     * ------------------------------------------------------------------
     * Rooms
     * ------------------------------------------------------------------
     */

    /**
     * Makes sure the rooms that are supposed to exist do.
     *
     * Called on the way in rather than from a schedule, because nothing in this
     * app is scheduled — the host scales to zero and a cron would never fire.
     * Idempotent and cheap: two lookups and, on the day a kid is added, one
     * insert.
     */
    public function ensureRooms(Household $household): void
    {
        foreach (FeedRoomKind::householdWide() as $kind) {
            FeedRoom::firstOrCreate([
                'household_id' => $household->id,
                'kind' => $kind,
            ]);
        }

        // One line to the grown-ups per kid. Created here rather than lazily,
        // because unlike a conversation with a sibling this one is always
        // available and a kid should not have to discover it exists.
        foreach ($household->profiles()->get() as $profile) {
            if (! $profile->isKid()) {
                continue;
            }

            FeedRoom::firstOrCreate([
                'household_id' => $household->id,
                'kind' => FeedRoomKind::Parents,
                'for_profile_id' => $profile->id,
            ]);
        }
    }

    /**
     * Every room `$viewer` can open, drawn-ready and newest first.
     *
     * One query for the rooms, one for the roster, one for the unread counts.
     * Everything else — names, audience lines, monograms — is derived in memory
     * off those three, because the room list is the page the kids open most and
     * a query per room is a query per room forever.
     *
     * @return array<int, array{room: FeedRoom, name: string, audience: string, glyph: ?string, monogram: ?string, accent: string, preview: ?string, unread: int, at: ?Carbon}>
     */
    public function roomsFor(Profile $viewer): array
    {
        $roster = $this->roster($viewer->household);

        $rooms = FeedRoom::where('household_id', $viewer->household_id)
            ->with(['memberRows', 'latestMessage.profile'])
            ->get()
            ->filter(fn (FeedRoom $room) => $room->readableBy($viewer))
            ->values();

        $unread = $this->unreadCounts($viewer, $rooms);

        return $rooms
            // Rooms that have never been used sort under the ones that have,
            // in their declared order, rather than jumping about by id.
            ->sortByDesc(fn (FeedRoom $room) => $room->last_message_at?->timestamp ?? 0)
            ->map(fn (FeedRoom $room) => [
                'room' => $room,
                'name' => $room->nameFor($viewer, $roster),
                'audience' => $room->audienceLineFor($viewer, $roster),
                'glyph' => $room->kind->glyph(),
                'monogram' => $room->monogramFor($viewer, $roster),
                'accent' => $room->accentFor($viewer, $roster),
                'preview' => $this->previewOf($room, $viewer),
                'unread' => $unread[$room->id] ?? 0,
                'at' => $room->last_message_at,
            ])
            ->values()
            ->all();
    }

    /**
     * The room `$viewer` asked for, or the one they land on.
     *
     * Everyone is the default, deliberately: a kid opening a room for the first
     * time should land where the whole house is, not in the quietest corner.
     */
    public function roomFor(Profile $viewer, ?int $roomId = null): ?FeedRoom
    {
        if ($roomId) {
            $room = FeedRoom::with('memberRows')->find($roomId);

            if ($room && $room->readableBy($viewer)) {
                return $room;
            }
        }

        return FeedRoom::where('household_id', $viewer->household_id)
            ->where('kind', FeedRoomKind::Everyone)
            ->first();
    }

    /**
     * The direct room holding these two, made if it isn't there yet.
     *
     * Lazily rather than up front: three kids and two grown-ups is ten possible
     * conversations, and nine empty ones in a list is nine things nobody has
     * said. A room appears the moment somebody actually uses it.
     */
    public function directRoomWith(Profile $viewer, Profile $other): FeedRoom
    {
        if ((int) $viewer->household_id !== (int) $other->household_id || (int) $viewer->id === (int) $other->id) {
            throw new RuntimeException('There is nobody to talk to there.');
        }

        $ids = [(int) $viewer->id, (int) $other->id];

        $existing = FeedRoom::where('household_id', $viewer->household_id)
            ->where('kind', FeedRoomKind::Direct)
            ->whereHas('memberRows', fn ($q) => $q->where('profile_id', $ids[0]))
            ->whereHas('memberRows', fn ($q) => $q->where('profile_id', $ids[1]))
            ->with('memberRows')
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($viewer, $ids) {
            $room = FeedRoom::create([
                'household_id' => $viewer->household_id,
                'kind' => FeedRoomKind::Direct,
            ]);

            foreach ($ids as $id) {
                FeedRoomMember::create(['room_id' => $room->id, 'profile_id' => $id]);
            }

            return $room->load('memberRows');
        });
    }

    /**
     * Every other person in the house, in a fixed order, each with their
     * one-to-one if it exists yet.
     *
     * The household is five people, so "Just you two" lists all of them up
     * front instead of hiding them behind a "Start one" picker. A name sits
     * where it always sits, which is what lets a six-year-old find his brother
     * without reading. Nobody's room is created by being listed: a person
     * without a conversation yet carries a stand-in entry, and the room is made
     * the first time that name is tapped — see directRoomWith().
     *
     * Takes roomsFor()'s result rather than querying again, because every
     * caller has just drawn the rooms and the direct ones are already in it.
     *
     * @param  array<int, array<string, mixed>>  $rooms
     * @return array<int, array{profile: Profile, started: bool, entry: array<string, mixed>}>
     */
    public function peopleFor(Profile $viewer, array $rooms): array
    {
        $byOther = [];

        foreach ($rooms as $entry) {
            if ($entry['room']->kind !== FeedRoomKind::Direct) {
                continue;
            }

            foreach ($entry['room']->memberIds() as $id) {
                if ($id !== (int) $viewer->id) {
                    $byOther[$id] = $entry;
                }
            }
        }

        return $this->roster($viewer->household)
            ->reject(fn (Profile $p) => (int) $p->id === (int) $viewer->id)
            ->map(fn (Profile $p) => [
                'profile' => $p,
                'started' => isset($byOther[(int) $p->id]),
                'entry' => $byOther[(int) $p->id] ?? [
                    'room' => null,
                    'name' => $p->name,
                    'audience' => '',
                    'glyph' => null,
                    'monogram' => mb_substr($p->name, 0, 1),
                    'accent' => $p->color?->cssVar() ?? 'var(--fq-text-3)',
                    'preview' => null,
                    'unread' => 0,
                    'at' => null,
                ],
            ])
            ->values()
            ->all();
    }

    /*
     * ------------------------------------------------------------------
     * Messages
     * ------------------------------------------------------------------
     */

    /**
     * A room's messages, oldest first, as a conversation reads.
     *
     * Reactions and authors come eager, because the page asks every message for
     * both — lazily, a room of sixty messages is a hundred and twenty queries.
     *
     * @return Collection<int, FeedMessage>
     */
    public function messagesIn(Profile $viewer, FeedRoom $room): Collection
    {
        abort_unless($room->readableBy($viewer), 403);

        return FeedMessage::where('room_id', $room->id)
            ->with([
                'profile',
                'subject',
                'reactions',
                // A quote row points at its Quote rather than copying it, so
                // the card in the room and the card on the Quote Wall show the
                // same reactions. Eager, and eager *through* the morph: the
                // faces under a quote name who laughed, which is a query per
                // reaction per quote if this is left to resolve itself.
                'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                    Quote::class => ['profile', 'reactions.profile'],
                ]),
            ])
            ->latest('id')
            ->limit(self::PER_ROOM)
            ->get()
            ->reverse()
            ->values();
    }

    /** Says something. Blank goes nowhere, and nothing is posted where it can't be read. */
    public function say(Profile $author, FeedRoom $room, string $body): ?FeedMessage
    {
        $body = $this->clean($body);

        if ($body === '') {
            return null;
        }

        return $this->write($author, $room, FeedMessageKind::Text, ['body' => $body]);
    }

    /** Sends a stamp — one picture, no bubble. See the FeedStamp enum. */
    public function stamp(Profile $author, FeedRoom $room, string $stamp): ?FeedMessage
    {
        $case = FeedStamp::tryFrom($stamp);

        if (! $case) {
            return null;
        }

        return $this->write($author, $room, FeedMessageKind::Stamp, ['stamp' => $case]);
    }

    /**
     * Posts a finger drawing.
     *
     * @throws RuntimeException when the canvas handed over something that isn't
     *                          a PNG, which the page shows rather than swallows
     */
    public function draw(Profile $author, FeedRoom $room, string $dataUrl): FeedMessage
    {
        abort_unless($room->readableBy($author), 403);

        return $this->write($author, $room, FeedMessageKind::Drawing, [
            'drawing_path' => $this->drawings->store($author, $dataUrl),
        ]);
    }

    /**
     * Says something *about* somebody, drawn behind a coral rule with their
     * name in the kicker.
     *
     * The subject has to be in the room. A shout-out about a kid who cannot
     * read it is a thing said behind their back with their name on it, which is
     * the exact opposite of what this is for.
     */
    public function shoutOut(Profile $author, FeedRoom $room, int $subjectId, string $body): ?FeedMessage
    {
        $body = $this->clean($body);
        $subject = $this->roster($author->household)->firstWhere('id', $subjectId);

        if ($body === '' || ! $subject || ! $room->readableBy($subject)) {
            return null;
        }

        return $this->write($author, $room, FeedMessageKind::Shoutout, [
            'body' => $body,
            'subject_id' => $subject->id,
        ]);
    }

    /**
     * Relays a quote a grown-up just wrote down into the Everyone room.
     *
     * This is where a quote lives now. It used to be a card at the bottom of
     * the kids' Home page — the last place on it anybody scrolls to — plus a
     * push notification to make up for that, and neither was the right shape:
     * a thing your brother said out loud is conversation, and conversation
     * belongs where the conversation is.
     *
     * Attributed to the kid who said it where there is one, so the row sits
     * under their name; to the grown-up who wrote it down otherwise, which is
     * what happens when the quote came from the dog or a visiting cousin.
     *
     * Deliberately *not* throttled like an event is. A funny day produces four
     * of these and all four are worth having — the throttle exists to stop the
     * app congratulating the same kid on the same kind of thing all afternoon,
     * and this isn't the app talking.
     */
    public function quote(Quote $quote): ?FeedMessage
    {
        $room = FeedRoom::where('household_id', $quote->household_id)
            ->where('kind', FeedRoomKind::Everyone)
            ->first();

        // The kid who said it, then the grown-up who wrote it down, then
        // anybody at all — a quote with neither (a line from the dog, typed by
        // a script) is still the house's news, and a row has to hang on
        // somebody.
        $author = Profile::find($quote->profile_id ?? $quote->added_by_profile_id)
            ?? Profile::where('household_id', $quote->household_id)->orderBy('id')->first();

        if (! $room || ! $author) {
            return null;
        }

        return $this->write($author, $room, FeedMessageKind::Quote, [
            // Copied as well as pointed at, so the room list's one-line preview
            // doesn't have to load a Quote per room to say what was said.
            'body' => $quote->text,
            'source_type' => $quote->getMorphClass(),
            'source_id' => $quote->getKey(),
        ]);
    }

    /**
     * The app saying something — a badge, a personal best, a streak milestone,
     * a monster killed.
     *
     * Always into Everyone, because the news is that somebody in this house did
     * a thing and the house is the audience. Drawn as one mono line with no
     * avatar and nothing to tap: the machine must never outweigh a person.
     *
     * Throttled to one per kid per source *type* per household-day, which is
     * what stops a kid on a hot streak at the arcade from filling the room with
     * their own scores. Returns null when the throttle swallowed it, so callers
     * that care can tell it apart from a post.
     *
     * `$body` may wrap the one thing worth lighting up in guillemets —
     * `slid «412 m» — new record` — which the view turns into a gold span
     * *after* escaping the line. Never put markup in here: a kid's name goes
     * into these strings, and a body rendered as raw HTML is how a feature like
     * this grows an XSS hole six months after anybody last looked at it.
     */
    public function event(Profile $about, string $body, ?Model $source = null): ?FeedMessage
    {
        $room = FeedRoom::where('household_id', $about->household_id)
            ->where('kind', FeedRoomKind::Everyone)
            ->first();

        if (! $room) {
            return null;
        }

        $sourceType = $source ? $source->getMorphClass() : null;
        $clock = HouseholdClock::for($about->household);

        $alreadyToday = FeedMessage::where('room_id', $room->id)
            ->where('profile_id', $about->id)
            ->where('kind', FeedMessageKind::Event)
            ->where('source_type', $sourceType)
            ->where('created_at', '>=', $clock->startOf($clock->today()))
            ->exists();

        if ($alreadyToday) {
            return null;
        }

        return $this->write($about, $room, FeedMessageKind::Event, [
            'body' => $this->clean($body),
            'source_type' => $sourceType,
            'source_id' => $source?->getKey(),
        ]);
    }

    /*
     * ------------------------------------------------------------------
     * Reactions and read markers
     * ------------------------------------------------------------------
     */

    /**
     * Adds a reaction, or takes your own back.
     *
     * One of each emoji per person — the unique index says so — which is what
     * keeps the count under a message a count of people rather than of taps.
     */
    public function react(Profile $viewer, int $messageId, string $emoji): void
    {
        if (! in_array($emoji, self::REACTIONS, true)) {
            return;
        }

        $message = FeedMessage::with('room.memberRows')->find($messageId);

        if (! $message || ! $message->room->readableBy($viewer)) {
            return;
        }

        // An event has no reaction affordance drawn on it, and nothing should
        // be able to put one there by posting straight at this method.
        if (! $message->kind->isFromAPerson()) {
            return;
        }

        $existing = FeedReaction::where('message_id', $message->id)
            ->where('profile_id', $viewer->id)
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();

            return;
        }

        FeedReaction::create([
            'message_id' => $message->id,
            'profile_id' => $viewer->id,
            'emoji' => $emoji,
        ]);
    }

    /** Marks a room read up to its last message. */
    public function markRead(Profile $viewer, FeedRoom $room): void
    {
        if (! $room->readableBy($viewer)) {
            return;
        }

        $last = (int) FeedMessage::where('room_id', $room->id)->max('id');

        FeedRoomRead::updateOrCreate(
            ['room_id' => $room->id, 'profile_id' => $viewer->id],
            ['last_read_message_id' => $last],
        );
    }

    /**
     * How many messages are waiting for `$viewer` in each of `$rooms`.
     *
     * Your own messages never count. A room that says "1" the second after you
     * post in it is a room that has taught a six-year-old the number means
     * nothing.
     *
     * @param  Collection<int, FeedRoom>  $rooms
     * @return array<int, int>
     */
    public function unreadCounts(Profile $viewer, Collection $rooms): array
    {
        if ($rooms->isEmpty()) {
            return [];
        }

        $ids = $rooms->pluck('id')->all();

        // Absent means nothing read — see the feed_room_reads migration.
        $marks = FeedRoomRead::where('profile_id', $viewer->id)
            ->whereIn('room_id', $ids)
            ->pluck('last_read_message_id', 'room_id');

        // One query, not one per room: each room carries its own threshold into
        // the same `where`, which is the only way this stays a single round
        // trip as the number of direct conversations grows.
        return FeedMessage::where('profile_id', '!=', $viewer->id)
            ->where(function ($query) use ($ids, $marks) {
                foreach ($ids as $id) {
                    $query->orWhere(fn ($q) => $q
                        ->where('room_id', $id)
                        ->where('id', '>', (int) ($marks[$id] ?? 0)));
                }
            })
            ->groupBy('room_id')
            ->selectRaw('room_id, count(*) as total')
            ->pluck('total', 'room_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /** The total waiting on `$viewer` across every room they can open. */
    public function unreadTotal(Profile $viewer): int
    {
        $rooms = FeedRoom::where('household_id', $viewer->household_id)
            ->with('memberRows')
            ->get()
            ->filter(fn (FeedRoom $room) => $room->readableBy($viewer))
            ->values();

        return array_sum($this->unreadCounts($viewer, $rooms));
    }

    /*
     * ------------------------------------------------------------------
     * The quiet half
     * ------------------------------------------------------------------
     */

    /**
     * Today's gratitude lists, as `$viewer` is allowed to see them.
     *
     * The `shared` flag decides, and an unshared list is drawn as an absence
     * with the person's name on it — "Westin kept his private" — rather than
     * left out. Somebody who opted out did a thing; silently omitting them
     * makes it look like they wrote nothing.
     *
     * @return array{lists: array<int, array{profile: Profile, items: array<int, string>}>, withheld: array<int, Profile>, total: int}
     */
    public function gratitudeToday(Profile $viewer): array
    {
        $gratitude = app(GratitudeService::class);
        $entries = $gratitude->todayForHousehold($viewer->household)->keyBy('profile_id');

        $lists = [];
        $withheld = [];

        foreach ($this->roster($viewer->household) as $profile) {
            $entry = $entries->get($profile->id);

            if (! $entry) {
                continue;
            }

            // Your own is always yours to read, whatever you chose.
            if ($entry->shared || (int) $profile->id === (int) $viewer->id) {
                $lists[] = ['profile' => $profile, 'items' => $entry->items ?? []];

                continue;
            }

            $withheld[] = $profile;
        }

        return [
            'lists' => $lists,
            'withheld' => $withheld,
            'total' => $entries->count(),
        ];
    }

    /**
     * Everyone's feeling for today, exactly as FeelingService already decides
     * it. Delegated rather than reimplemented: that method knows that the word
     * is household-public and only the *because* has a door on it, and a second
     * copy of that rule is how one of them ends up backwards.
     *
     * Null while the viewer hasn't answered their own — the feelings card's own
     * rule, kept here on purpose. Reading the house's answers is what answering
     * buys you, and a second door into them would quietly undo that.
     *
     * @return \Illuminate\Support\Collection<int, array{profile: Profile, entry: ?FeelingEntry, because: ?string}>|null
     */
    public function houseToday(Profile $viewer): ?\Illuminate\Support\Collection
    {
        return app(FeelingService::class)->houseToday($viewer);
    }

    /*
     * ------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------
     */

    /**
     * The household, by name.
     *
     * @return Collection<int, Profile>
     */
    public function roster(Household $household): Collection
    {
        return $this->roster[$household->id] ??= $household->profiles()->orderBy('name')->get();
    }

    /**
     * The one shared write path. Everything that posts goes through here, so
     * that the readability check and the room's `last_message_at` can never be
     * forgotten by a new kind of message.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function write(Profile $author, FeedRoom $room, FeedMessageKind $kind, array $attributes): FeedMessage
    {
        abort_unless($room->readableBy($author), 403);

        $message = DB::transaction(function () use ($author, $room, $kind, $attributes) {
            $message = FeedMessage::create([
                'household_id' => $author->household_id,
                'room_id' => $room->id,
                'profile_id' => $author->id,
                'kind' => $kind,
                ...$attributes,
            ]);

            $room->forceFill(['last_message_at' => $message->created_at])->save();

            return $message;
        });

        $this->notifyRoom($author, $room, $message);

        return $message;
    }

    /**
     * Pushes a new message to everybody else in the room.
     *
     * The kids have no phones, so this is the difference between a conversation
     * and a noticeboard. Sent one recipient at a time because a room's name is
     * different depending on who is reading it — a kid's line to the grown-ups
     * is "Mom and Dad" to him and "Raylan & grown-ups" to them.
     *
     * Three things never push:
     *
     * - **The app talking.** An event is the quietest thing in the room on
     *   purpose; buzzing a phone about somebody else's badge would make it the
     *   loudest.
     * - **A quote.** Its push was removed at the user's request when quotes
     *   moved into the feed, and the unread count covers it.
     * - **The Kids room, to a grown-up.** Parents can open that room and the
     *   header says so, but a phone buzzing with every line the kids say to each
     *   other turns "a quieter room, not a secret one" into a room being
     *   listened to. They still get pushed for every room that is theirs.
     *
     * Failures are logged, never thrown. A dead push subscription must not lose
     * the message that was just sent.
     */
    private function notifyRoom(Profile $author, FeedRoom $room, FeedMessage $message): void
    {
        if (in_array($message->kind, [FeedMessageKind::Event, FeedMessageKind::Quote], true)) {
            return;
        }

        $roster = $this->roster($author->household);

        $recipients = $room->membersFrom($roster)
            ->reject(fn (Profile $p) => (int) $p->id === (int) $author->id)
            ->reject(fn (Profile $p) => $room->kind === FeedRoomKind::Kids && $p->isParent());

        foreach ($recipients as $recipient) {
            $roomName = $room->nameFor($recipient, $roster);

            $title = match (true) {
                $message->kind === FeedMessageKind::Shoutout => $author->name.' gave '
                    .((int) $message->subject_id === (int) $recipient->id ? 'you' : $message->subject?->name)
                    .' a shout-out',
                $room->kind === FeedRoomKind::Direct => $author->name,
                default => $author->name.' in '.$roomName,
            };

            $body = match ($message->kind) {
                FeedMessageKind::Stamp => ($message->stamp?->glyph() ?? '').' '.($message->stamp?->label() ?? ''),
                FeedMessageKind::Drawing => 'Sent a drawing',
                default => (string) $message->body,
            };

            try {
                $recipient->notify(new FeedMessagePosted(
                    $room->id,
                    $title,
                    mb_strimwidth(trim($body), 0, 140, '…'),
                    // Kids land on Home, which is where the feed lives for them.
                    $recipient->isParent() ? '/parent/family' : '/kid/home',
                ));
            } catch (Throwable $e) {
                Log::error('Family feed notification failed.', [
                    'feed_message_id' => $message->id,
                    'profile_id' => $recipient->id,
                    'exception' => $e,
                ]);
            }
        }
    }

    /** The room list's one line: who said it and what it was. */
    private function previewOf(FeedRoom $room, Profile $viewer): ?string
    {
        $last = $room->latestMessage;

        if (! $last) {
            return null;
        }

        $body = $last->preview();

        // Unattributed in a one-to-one — there are only two of you and the
        // name is the room's own title, printed directly above it.
        if ($room->kind === FeedRoomKind::Direct || ! $last->kind->isFromAPerson()) {
            return $body;
        }

        $who = (int) $last->profile_id === (int) $viewer->id ? 'You' : $last->profile?->name;

        return $last->kind->previewVerb()
            ? "{$who} ".$last->kind->previewVerb()
            : "{$who}: {$body}";
    }

    /** Collapses whitespace and caps the length, then trims what the cap left. */
    private function clean(string $body): string
    {
        $body = trim(preg_replace('/[ \t]+/u', ' ', $body) ?? '');

        return trim(mb_substr($body, 0, self::MAX_BODY));
    }
}
