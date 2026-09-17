<?php

namespace App\Console\Commands;

use App\Models\Cosmetic;
use App\Services\CosmeticArt;
use Illuminate\Console\Command;

/**
 * Runs uploaded cosmetic art back through the tidying it gets on upload.
 *
 * That tidying gets better — the checkerboard cut, the halo faded, the ruled
 * grid rubbed out — and art uploaded before an improvement keeps whatever it
 * arrived with. Re-uploading would work too, but it makes a *second* item that
 * nobody owns, so a kid wearing the old one is stuck with it.
 *
 * Safe to run twice: cleaning already-clean art finds nothing to do.
 */
class CleanCosmeticArtCommand extends Command
{
    protected $signature = 'cosmetics:clean-art {--id=* : Only these cosmetics} {--pretend : Say what would change and write nothing}';

    protected $description = 'Re-run the upload tidy-up over stored cosmetic art';

    public function handle(CosmeticArt $art): int
    {
        $items = Cosmetic::whereNotNull('art_path')
            ->when($this->option('id'), fn ($query, $ids) => $query->whereIn('id', $ids))
            ->get();

        if ($items->isEmpty()) {
            $this->info('No uploaded art to clean.');

            return self::SUCCESS;
        }

        $disk = $art->disk();
        $changed = 0;

        foreach ($items as $item) {
            if (! $disk->exists($item->art_path)) {
                $this->warn("{$item->name}: the file is missing from the disk.");

                continue;
            }

            $before = (string) $disk->get($item->art_path);
            $tidied = $art->normalize($before, $item->slot);
            $notes = collect($tidied['checks'])->pluck('label')->implode('; ');

            if ($tidied['binary'] === $before) {
                $this->line("{$item->name}: already clean.");

                continue;
            }

            $this->info("{$item->name}: ".($notes !== '' ? $notes : 'redrawn'));
            $changed++;

            if ($this->option('pretend')) {
                continue;
            }

            $disk->put($item->art_path, $tidied['binary']);

            // The art's URL carries this timestamp, so the browser fetches the
            // cleaned picture instead of the one it already has.
            $item->touch();
        }

        $this->newLine();
        $this->info($this->option('pretend')
            ? "{$changed} would change."
            : "{$changed} cleaned.");

        return self::SUCCESS;
    }
}
