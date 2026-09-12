<?php

namespace App\Models;

use App\Enums\FeedRoomKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One room of the family feed. See the create migration for the whole policy.
 */
class FeedRoom extends Model
{
    use HasFactory;

    protected $fillable = [
        'household_id',
        'kind',
        'for_profile_id',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => FeedRoomKind::class,
            'last_message_at' => 'datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** The kid a `parents` room belongs to. Null for every other kind. */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'for_profile_id');
    }

    /**
     * The membership rows, which only a `direct` room has — every other kind
     * derives its members from role. See the feed_room_members migration.
     */
    public function memberRows(): HasMany
    {
        return $this->hasMany(FeedRoomMember::class, 'room_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(FeedMessage::class, 'room_id');
    }

    /**
     * The last thing anybody said here, for the room list's one-line preview.
     * A relation rather than a column, so that the preview is never a copy of a
     * message that has since been deleted.
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(FeedMessage::class, 'room_id')->latestOfMany();
    }

    /**
     * Whether `$viewer` may read this room — and, since there is no room here
     * anyone may read but not write in, whether they may post in it.
     *
     * This is the single gate. Every list, every header and every post goes
     * through it, so that the policy is one method with one test per branch
     * rather than a condition inlined at each call site. Getting one of these
     * backwards is the only bug in this feature that would actually matter.
     */
    public function readableBy(Profile $viewer): bool
    {
        // Never across households, whatever the kind says.
        if ((int) $viewer->household_id !== (int) $this->household_id) {
            return false;
        }

        return match ($this->kind) {
            // The whole house, which is what it is called.
            FeedRoomKind::Everyone => true,

            // The kids' room is open to the grown-ups. A decision, not an
            // oversight: it is a quieter room, not a secret one — and the
            // header says so out loud, every time, so that nobody discovers
            // the audience after they have posted.
            FeedRoomKind::Kids => true,

            // One kid's line to the grown-ups: that kid, and the grown-ups.
            // A sibling is not in it.
            FeedRoomKind::Parents => $viewer->isParent()
                || (int) $viewer->id === (int) $this->for_profile_id,

            // Exactly the two people in it — including when both of them are
            // kids. That was asked and answered directly: full trust. It is
            // the one rule here that cannot be quietly changed later without
            // breaking a promise the header has already made.
            FeedRoomKind::Direct => in_array((int) $viewer->id, $this->memberIds(), true),
        };
    }

    /**
     * The ids of a direct room's two members. Empty for every other kind,
     * whose membership is derived — see membersFrom().
     *
     * @return array<int, int>
     */
    public function memberIds(): array
    {
        return $this->memberRows->map(fn (FeedRoomMember $row) => (int) $row->profile_id)->all();
    }

    /**
     * Who is in this room, resolved against the household roster.
     *
     * Takes the roster rather than querying, because every caller is drawing a
     * list of rooms and already has it — one query for the house beats one per
     * room, and the derived kinds have nothing to query anyway.
     *
     * @param  Collection<int, Profile>  $roster
     * @return Collection<int, Profile>
     */
    public function membersFrom(Collection $roster): Collection
    {
        return match ($this->kind) {
            FeedRoomKind::Everyone => $roster,
            FeedRoomKind::Kids => $roster->filter(fn (Profile $p) => $p->isKid())->values(),
            FeedRoomKind::Parents => $roster
                ->filter(fn (Profile $p) => $p->isParent() || (int) $p->id === (int) $this->for_profile_id)
                ->values(),
            FeedRoomKind::Direct => $roster
                ->filter(fn (Profile $p) => in_array((int) $p->id, $this->memberIds(), true))
                ->values(),
        };
    }

    /**
     * What this room is called on `$viewer`'s screen.
     *
     * Two of the four are named differently depending on who is looking, and
     * that is not a flourish: a kid's line to the grown-ups is "Mom & Dad" to
     * him and "Raylan & grown-ups" to her, because from her side there are
     * three of them and the only thing that tells them apart is whose they are.
     *
     * @param  Collection<int, Profile>  $roster
     */
    public function nameFor(Profile $viewer, Collection $roster): string
    {
        return match ($this->kind) {
            FeedRoomKind::Everyone => 'Everyone',
            FeedRoomKind::Kids => 'Kids',
            FeedRoomKind::Parents => $viewer->isParent()
                ? $this->subjectNameFrom($roster).' & grown-ups'
                : self::joinNames($roster->filter(fn (Profile $p) => $p->isParent())->pluck('name')->all()),
            FeedRoomKind::Direct => $this->membersFrom($roster)
                ->first(fn (Profile $p) => (int) $p->id !== (int) $viewer->id)?->name ?? 'Just you',
        };
    }

    /**
     * The line under the room name, which is always drawn.
     *
     * Load-bearing copy. A kid must learn the audience *before* they post, not
     * discover it afterwards, so this is never conditional, never truncated and
     * never a tooltip. If the policy in readableBy() ever changes, this changes
     * in the same commit — a header that lies once has never told the truth.
     *
     * @param  Collection<int, Profile>  $roster
     */
    public function audienceLineFor(Profile $viewer, Collection $roster): string
    {
        $parents = $roster->filter(fn (Profile $p) => $p->isParent())->pluck('name')->all();

        return match ($this->kind) {
            FeedRoomKind::Everyone => 'All '.self::spell($roster->count()).' of you can read this',

            // States the asymmetry rather than hiding it, on everybody's
            // screen. A parent reading their own name here is being told the
            // same true thing the kids are.
            FeedRoomKind::Kids => 'The '.self::spell($roster->filter(fn (Profile $p) => $p->isKid())->count())
                .' of you can read this — '.self::joinNames($parents).' can open it too',

            FeedRoomKind::Parents, FeedRoomKind::Direct => 'Just '
                .self::joinNames($this->membersFrom($roster)
                    ->sortBy(fn (Profile $p) => (int) $p->id === (int) $viewer->id ? 0 : 1)
                    ->map(fn (Profile $p) => (int) $p->id === (int) $viewer->id ? 'you' : $p->name)
                    ->values()
                    ->all()),
        };
    }

    /**
     * The letters in the room's tile, where it has no glyph of its own. A kid's
     * line to the grown-ups wears their initials; a direct room wears the other
     * person's first letter.
     *
     * @param  Collection<int, Profile>  $roster
     */
    public function monogramFor(Profile $viewer, Collection $roster): ?string
    {
        return match ($this->kind) {
            FeedRoomKind::Everyone, FeedRoomKind::Kids => null,
            FeedRoomKind::Parents => $viewer->isParent()
                ? mb_substr($this->subjectNameFrom($roster), 0, 1)
                : $roster->filter(fn (Profile $p) => $p->isParent())
                    ->map(fn (Profile $p) => mb_substr($p->name, 0, 1))
                    ->implode(''),
            FeedRoomKind::Direct => mb_substr($this->nameFor($viewer, $roster), 0, 1),
        };
    }

    /**
     * The colour the room's monogram is drawn in — the accent of the person it
     * is about, so that a list of rooms is scannable before it is read.
     *
     * @param  Collection<int, Profile>  $roster
     */
    public function accentFor(Profile $viewer, Collection $roster): string
    {
        $other = match ($this->kind) {
            FeedRoomKind::Parents => $viewer->isParent()
                ? $roster->firstWhere('id', $this->for_profile_id)
                : $roster->first(fn (Profile $p) => $p->isParent()),
            FeedRoomKind::Direct => $this->membersFrom($roster)
                ->first(fn (Profile $p) => (int) $p->id !== (int) $viewer->id),
            default => null,
        };

        return $other?->color?->cssVar() ?? 'var(--fq-text-3)';
    }

    /**
     * The placeholder in the composer, which names the room on purpose: the
     * field is the last thing looked at before somebody says something, so it
     * is the last chance to say who will hear it.
     *
     * @param  Collection<int, Profile>  $roster
     */
    public function composerPlaceholderFor(Profile $viewer, Collection $roster): string
    {
        return match ($this->kind) {
            FeedRoomKind::Everyone => 'Message everyone…',
            FeedRoomKind::Kids => 'Message the kids…',
            FeedRoomKind::Parents, FeedRoomKind::Direct => 'Message '.$this->nameFor($viewer, $roster).'…',
        };
    }

    /** @param  Collection<int, Profile>  $roster */
    private function subjectNameFrom(Collection $roster): string
    {
        return $roster->firstWhere('id', $this->for_profile_id)?->name ?? 'A kid';
    }

    /**
     * "Mom", "Mom and Dad", "you, Mom and Dad". Plain English rather than a
     * comma-separated list, because this copy is read by a six-year-old.
     *
     * @param  array<int, string>  $names
     */
    private static function joinNames(array $names): string
    {
        $names = array_values(array_filter($names));

        if ($names === []) {
            return 'nobody';
        }

        if (count($names) === 1) {
            return $names[0];
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }

    /** Counts read as words in this copy — "All five of you", not "All 5". */
    private static function spell(int $n): string
    {
        return [
            2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
            6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine',
        ][$n] ?? (string) $n;
    }
}
