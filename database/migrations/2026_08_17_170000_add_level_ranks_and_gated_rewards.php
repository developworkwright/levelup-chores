<?php

use App\Enums\ProfileRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three things in one step, because they only make sense together: rewards can
 * now be locked behind a level, the level curve steps up at 11 and 21, and
 * every kid keeps the level they already had.
 *
 * That last part is the whole reason `xp_adjustment` exists. Steepening the
 * curve under kids who have already climbed it would take levels back off them
 * overnight — Nova would drop from 16 to 13 — which would teach exactly the
 * opposite lesson from the one the change is for. So the conversion banks the
 * difference as XP and each kid wakes up on the same level, on the same point
 * of the same bar, with only the *next* level costing more.
 *
 * It's a real column rather than a one-off UPDATE because `xp:reconcile`
 * rebuilds XP from the records that earned it. An untracked top-up would look
 * like drift to that command and get flattened the first time someone ran it
 * with --allow-decrease; a column it knows to add back survives.
 *
 * The curves are written out here rather than read off Profile on purpose. A
 * migration has to keep meaning what it meant on the day it ran, and this one
 * is a bridge between two specific curves — if the bands move again, this file
 * must not move with them.
 */
return new class extends Migration
{
    /** The flat curve every existing kid climbed. */
    private const OLD_XP_PER_LEVEL = 200;

    /** @var array<int, array{through: int|null, cost: int}> */
    private const NEW_BANDS = [
        ['through' => 10, 'cost' => 200],
        ['through' => 20, 'cost' => 350],
        ['through' => null, 'cost' => 500],
    ];

    public function up(): void
    {
        Schema::table('store_items', function (Blueprint $table) {
            // 0, not null: "no gate" is a number on the same scale as a gate,
            // which keeps every comparison a plain `level < min_level`.
            $table->unsignedInteger('min_level')->default(0)->after('color_tag');
        });

        Schema::table('profiles', function (Blueprint $table) {
            // Signed. It only ever goes up in this migration, but a later
            // curve change that softens the bands would need to hand XP back.
            $table->integer('xp_adjustment')->default(0)->after('xp');
        });

        $this->preserveLevels();
    }

    public function down(): void
    {
        // Unwinding the conversion first, so XP means under the old curve what
        // it meant before this ran. Dropping the column without this would
        // leave every kid over-credited and several levels up.
        DB::table('profiles')->where('xp_adjustment', '<>', 0)->update([
            'xp' => DB::raw('xp - xp_adjustment'),
        ]);

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('xp_adjustment');
        });

        Schema::table('store_items', function (Blueprint $table) {
            $table->dropColumn('min_level');
        });
    }

    /**
     * Tops every kid up to the XP the new curve wants for the level and bar
     * position they already had, and records what was added.
     */
    private function preserveLevels(): void
    {
        $kids = DB::table('profiles')
            ->where('role', ProfileRole::Kid->value)
            ->get(['id', 'xp']);

        foreach ($kids as $kid) {
            $xp = (int) $kid->xp;

            $level = 1 + intdiv($xp, self::OLD_XP_PER_LEVEL);
            $throughLevel = ($xp % self::OLD_XP_PER_LEVEL) / self::OLD_XP_PER_LEVEL;

            $converted = $this->xpToReachLevel($level)
                + (int) round($this->xpToClearLevel($level) * $throughLevel);

            $adjustment = $converted - $xp;

            if ($adjustment === 0) {
                continue;
            }

            DB::table('profiles')->where('id', $kid->id)->update([
                'xp' => $converted,
                'xp_adjustment' => $adjustment,
            ]);
        }
    }

    private function xpToClearLevel(int $level): int
    {
        foreach (self::NEW_BANDS as $band) {
            if ($band['through'] === null || $level <= $band['through']) {
                return $band['cost'];
            }
        }

        return self::OLD_XP_PER_LEVEL;
    }

    private function xpToReachLevel(int $level): int
    {
        $total = 0;

        for ($crossed = 1; $crossed < $level; $crossed++) {
            $total += $this->xpToClearLevel($crossed);
        }

        return $total;
    }
};
