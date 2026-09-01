<?php

namespace App\Services;

use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Profile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Playlists — a kid's own running order through the music library.
 *
 * The library is a folder of mp3s and has no table (see MusicService); a
 * playlist is a list of ids pointing into it. That split is the whole of what
 * makes this class interesting, because nothing in the database can stop a song
 * being renamed or deleted out from under a list that names it.
 *
 * Two halves answer that. Every id a kid sends is checked against the live
 * library before it is stored, so nothing invented gets in; and the music
 * screen calls retrack() and untrack() when a parent renames or deletes a song,
 * so the lists follow. What is left over — a file moved in the bucket by hand —
 * survives as an entry whose song cannot be found, and those are drawn on the
 * kid's music page greyed out with a Remove button rather than disappearing on
 * their own. A kid who made a list of twelve songs should never be told it has
 * eleven without being told which one went.
 */
class PlaylistService
{
    /**
     * How many lists a kid may keep.
     *
     * Not a storage limit — twelve rows is nothing. It is a limit on the mess:
     * the picker in the header is a panel on a phone, and past a dozen entries
     * choosing a playlist becomes its own scrolling problem, which is the exact
     * problem playlists exist to solve.
     */
    public const MAX_PER_PROFILE = 12;

    /** Long enough for a soundtrack's worth of favourites. */
    public const MAX_TRACKS = 100;

    /** Matches the column, and about as much as fits a picker row on a phone. */
    public const MAX_NAME = 40;

    /**
     * The library, read once per request rather than per call.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $library = null;

    public function __construct(private MusicService $music) {}

    /**
     * Every playlist a kid owns, songs and all.
     *
     * @return Collection<int, Playlist>
     */
    public function forProfile(Profile $profile): Collection
    {
        return Playlist::query()
            ->where('profile_id', $profile->id)
            ->with('tracks')
            // By name, because that is how a kid looks for one. Creation order
            // is meaningless to them by the third list.
            ->orderBy('name')
            ->get();
    }

    /**
     * The playlists as the browser receives them.
     *
     * Ids only, no titles or urls: the header already has the whole library in
     * hand, so sending each song's details again would be the same markup a
     * second time on every page load. Entries whose song is missing are dropped
     * here rather than sent and skipped — the player has no way to show a kid
     * what went wrong, so it should not have to know.
     *
     * @return array<int, array{id: int, name: string, trackIds: array<int, string>}>
     */
    public function payloadFor(Profile $profile): array
    {
        $library = $this->library();

        return $this->forProfile($profile)
            ->map(fn (Playlist $playlist): array => [
                'id' => $playlist->id,
                'name' => $playlist->name,
                'trackIds' => $playlist->tracks
                    ->pluck('track_id')
                    ->filter(fn (string $id): bool => isset($library[$id]))
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * One playlist's songs as the kid's music page draws them.
     *
     * `track` is null for an entry whose file is gone — the row a kid can see
     * and remove. See the class docblock for why those are shown rather than
     * quietly filtered.
     *
     * @return array<int, array{entry: PlaylistTrack, track: array<string, mixed>|null}>
     */
    public function songsIn(Playlist $playlist): array
    {
        $library = $this->library();

        return $playlist->tracks
            ->map(fn (PlaylistTrack $entry): array => [
                'entry' => $entry,
                'track' => $library[$entry->track_id] ?? null,
            ])
            ->all();
    }

    /**
     * Why this name cannot be used, in words a kid can read, or null if it can.
     *
     * Separate from create() so the page can say what went wrong rather than
     * just failing to make anything. `$except` is the playlist being renamed,
     * which is allowed to keep its own name.
     */
    public function rejection(Profile $profile, string $name, ?Playlist $except = null): ?string
    {
        $name = $this->clean($name);

        if ($name === '') {
            return 'Give it a name first.';
        }

        if ($except === null && $this->countFor($profile) >= self::MAX_PER_PROFILE) {
            return 'That is '.self::MAX_PER_PROFILE.' playlists already — delete one to make room.';
        }

        // Case-insensitively, and in PHP rather than as a unique index: MySQL
        // would call these the same and SQLite would not, and a rule that
        // depends on which database it lands in is not a rule.
        $taken = Playlist::query()
            ->where('profile_id', $profile->id)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->get()
            ->contains(fn (Playlist $playlist): bool => strcasecmp($playlist->name, $name) === 0);

        return $taken ? 'You already have a playlist called that.' : null;
    }

    public function create(Profile $profile, string $name): ?Playlist
    {
        if ($this->rejection($profile, $name) !== null) {
            return null;
        }

        return Playlist::create([
            'profile_id' => $profile->id,
            'name' => $this->clean($name),
        ]);
    }

    public function rename(Playlist $playlist, string $name): bool
    {
        if ($this->rejection($playlist->profile, $name, $playlist) !== null) {
            return false;
        }

        return $playlist->update(['name' => $this->clean($name)]);
    }

    /**
     * Bin a playlist and everything in it.
     *
     * The songs go too, and they go from here rather than from the database:
     * there are no foreign keys on these tables, so nothing cascades and rows
     * left behind would be invisible orphans keyed to an id that is free to be
     * handed to somebody else's playlist later.
     */
    public function delete(Playlist $playlist): void
    {
        DB::transaction(function () use ($playlist): void {
            PlaylistTrack::where('playlist_id', $playlist->id)->delete();
            $playlist->delete();
        });
    }

    /**
     * Put a song at the end of a playlist.
     *
     * The id is checked against the live library, not trusted: it arrives from
     * a browser, and an entry naming a file that never existed is one nothing
     * can ever clean up, because no rename or delete will go looking for it.
     */
    public function add(Playlist $playlist, string $trackId): bool
    {
        $track = $this->library()[$trackId] ?? null;

        if ($track === null || $playlist->tracks()->count() >= self::MAX_TRACKS) {
            return false;
        }

        // Already in it. A kid tapping twice has got what they wanted either
        // way, and the unique index means the race between the two taps ends
        // here rather than in an error page.
        if ($playlist->tracks()->where('track_id', $trackId)->exists()) {
            return false;
        }

        PlaylistTrack::create([
            'playlist_id' => $playlist->id,
            'track_id' => $trackId,
            'title' => $track['title'],
            'position' => (int) $playlist->tracks()->max('position') + 1,
        ]);

        $playlist->unsetRelation('tracks');

        return true;
    }

    public function remove(Playlist $playlist, string $trackId): void
    {
        $playlist->tracks()->where('track_id', $trackId)->delete();

        $playlist->unsetRelation('tracks');

        $this->resequence($playlist);
    }

    /**
     * Nudge a song one place up (-1) or down (+1).
     *
     * A swap with its neighbour rather than a rewrite of the whole list: it is
     * exactly what the two arrow buttons mean, and it leaves every other
     * position alone.
     */
    public function move(Playlist $playlist, string $trackId, int $direction): void
    {
        $entries = $playlist->tracks()->get()->values();
        $index = $entries->search(fn (PlaylistTrack $entry): bool => $entry->track_id === $trackId);

        if ($index === false) {
            return;
        }

        $target = $index + ($direction < 0 ? -1 : 1);

        // The ends are not an error. A kid pressing the up arrow on the first
        // song is asking for nothing, not for a wrap around to the bottom.
        if ($target < 0 || $target >= $entries->count()) {
            return;
        }

        $moving = $entries[$index];
        $displaced = $entries[$target];

        DB::transaction(function () use ($moving, $displaced): void {
            [$moving->position, $displaced->position] = [$displaced->position, $moving->position];

            $moving->save();
            $displaced->save();
        });

        $playlist->unsetRelation('tracks');
    }

    /**
     * Follow a song that has been retitled into its new id.
     *
     * Called from the music screen, because a rename is a move on disk and the
     * id is a slug of the path — so every playlist naming the old one would
     * otherwise be pointing at a file that is no longer there. Across every
     * kid's lists at once: the song is the house's, the lists are not.
     */
    public function retrack(string $oldId, string $newId, string $title): void
    {
        if ($oldId === $newId) {
            return;
        }

        /*
         * One at a time rather than a bulk update, because of the unique index
         * on (playlist_id, track_id). A kid whose playlist already holds the
         * *target* id — the parent renamed a song to match one already in the
         * list — would take the whole update down with a duplicate key, so
         * those entries are dropped instead. The list ends up with one row for
         * the song, which is what it would have had all along.
         */
        foreach (PlaylistTrack::where('track_id', $oldId)->get() as $entry) {
            $clash = PlaylistTrack::where('playlist_id', $entry->playlist_id)
                ->where('track_id', $newId)
                ->exists();

            $clash
                ? $entry->delete()
                : $entry->update(['track_id' => $newId, 'title' => $title]);
        }
    }

    /** Take a deleted song out of every playlist that named it. */
    public function untrack(string $trackId): void
    {
        PlaylistTrack::where('track_id', $trackId)->delete();
    }

    public function countFor(Profile $profile): int
    {
        return Playlist::where('profile_id', $profile->id)->count();
    }

    /** Close the gaps left by a removal, so positions stay 1..n. */
    private function resequence(Playlist $playlist): void
    {
        $position = 0;

        foreach ($playlist->tracks()->get() as $entry) {
            $entry->update(['position' => ++$position]);
        }

        $playlist->unsetRelation('tracks');
    }

    /**
     * The library keyed by id.
     *
     * Memoised for the request: a page drawing four playlists asks four times,
     * and on a bucket that is a listing each time.
     *
     * @return array<string, array<string, mixed>>
     */
    private function library(): array
    {
        return $this->library ??= collect($this->music->tracks())->keyBy('id')->all();
    }

    private function clean(string $name): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $name) ?? ''), 0, self::MAX_NAME);
    }
}
