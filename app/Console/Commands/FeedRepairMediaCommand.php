<?php

namespace App\Console\Commands;

use App\Enums\FeedMessageKind;
use App\Models\FeedMessage;
use App\Services\FeedDrawings;
use App\Services\FeedPhotos;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Re-points feed pictures whose stored path no longer matches where the file
 * actually is.
 *
 * The drawings that broke were written before the folder moved *into* the path.
 * `FeedDrawings::store()` used to hand the disk `1/abc.png` and let the disk's
 * own `root` file it under `drawings/`; it now writes `drawings/1/abc.png` so
 * that the folder is the same on every disk, local or bucket. The files never
 * moved — the rows simply describe them the old way, and a controller that
 * streams exactly what the row says 404s for everybody who was in the room when
 * the picture was drawn.
 *
 * So this moves, copies and deletes nothing. For each row whose file is missing
 * it looks for the same name written the other way round, and rewrites the row
 * **only when the file is genuinely there**. A picture that is on no disk at all
 * is left exactly as it is: the row is the only remaining record that it ever
 * existed, and deciding to drop those is a person's call, not a repair script's.
 */
class FeedRepairMediaCommand extends Command
{
    protected $signature = 'feed:repair-media
        {--apply : Write the repaired paths. Without this the command only reports.}';

    protected $description = 'Re-point feed drawings and photos whose stored path misses the file but whose file is on the disk under the other name.';

    public function handle(FeedDrawings $drawings, FeedPhotos $photos): int
    {
        $apply = (bool) $this->option('apply');

        // Named up front, because it is half the answer: a run that finds
        // nothing on a disk nobody expected is a different problem from a run
        // that finds nothing on the right one.
        $this->line('Disk in use: <options=bold>'.config('filesystems.drawings_disk').'</>');

        $pictures = FeedMessage::whereIn('kind', [FeedMessageKind::Drawing, FeedMessageKind::Photo])
            ->orderBy('id')
            ->get();

        $rows = [];
        $repairable = 0;
        $lost = 0;

        foreach ($pictures as $picture) {
            $drawing = $picture->kind === FeedMessageKind::Drawing;
            $column = $drawing ? 'drawing_path' : 'image_path';
            $folder = $drawing ? FeedDrawings::FOLDER : FeedPhotos::FOLDER;
            $disk = $drawing ? $drawings->disk() : $photos->disk();

            $path = $picture->{$column};

            if ($path === null || $disk->exists($path)) {
                continue;
            }

            $found = $this->findElsewhere($disk, $path, $folder);

            if ($found === null) {
                $lost++;
                $rows[] = [$picture->id, $picture->kind->value, $path, 'no file on this disk', '—'];

                continue;
            }

            $repairable++;
            $rows[] = [$picture->id, $picture->kind->value, $path, $found, $apply ? 'repaired' : 'would repair'];

            if ($apply) {
                $picture->forceFill([$column => $found])->save();
            }
        }

        if ($rows === []) {
            $this->info('Every drawing and photo already points at a file that exists.');

            return self::SUCCESS;
        }

        $this->table(['#', 'Kind', 'Stored path', 'Found at', 'Action'], $rows);
        $this->line("{$repairable} repairable, {$lost} with no file on this disk.");

        if (! $apply && $repairable > 0) {
            $this->warn('Nothing was written. Run it again with --apply to save the repaired paths.');
        }

        return self::SUCCESS;
    }

    /**
     * The same file, named the other way round.
     *
     * Two candidates and no more: the folder prefixed, for a row written before
     * the folder lived in the path, and the folder stripped, for the reverse —
     * a disk whose own `root` already ends in that folder files
     * `drawings/1/a.png` at `drawings/drawings/1/a.png`, and the row that comes
     * back from that is the mirror image of this bug.
     */
    private function findElsewhere(Filesystem $disk, string $path, string $folder): ?string
    {
        $candidate = str_starts_with($path, "{$folder}/")
            ? substr($path, strlen($folder) + 1)
            : "{$folder}/{$path}";

        return $disk->exists($candidate) ? $candidate : null;
    }
}
