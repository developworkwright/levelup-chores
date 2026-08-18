<?php

use App\Enums\ChoreIcon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `chores.icon` stops being a key and becomes a Font Awesome class.
 *
 * The original column held `'dishes'` so the drawing behind it could be
 * redrawn without a data migration. That bet paid out exactly once — here —
 * and the reason to stop taking it is the feature that replaces it: a parent
 * can now type any Font Awesome class they like, so the column has to hold
 * values no enum could ever list. A key would mean two formats in one column
 * and a resolver in front of every read.
 *
 * Widened to 64 to fit a style plus a name (`fa-solid fa-window-maximize` is
 * already 26), which is the same ceiling {@see ChoreIcon::normalizeClass()}
 * enforces on the way in.
 *
 * The sixteen presets are read off the enum rather than copied here. Same
 * reasoning as the migration that added the column: this is a convenience, not
 * a record of what happened, so a re-run landing on today's icons rather than
 * August's costs nothing — where a *payout* migration doing the same would be
 * rewriting history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->string('icon', 64)->nullable()->change();
        });

        foreach (ChoreIcon::cases() as $icon) {
            DB::table('chores')->where('icon', $icon->value)->update(['icon' => $icon->faClass()]);
        }
    }

    public function down(): void
    {
        foreach (ChoreIcon::cases() as $icon) {
            DB::table('chores')->where('icon', $icon->faClass())->update(['icon' => $icon->value]);
        }

        // Anything a parent typed by hand has no key to go back to, and a
        // truncation to 32 would leave a class that renders nothing. Clearing
        // it drops the card to its typographic face, which is a face.
        DB::table('chores')
            ->whereNotNull('icon')
            ->whereNotIn('icon', array_map(fn (ChoreIcon $icon): string => $icon->value, ChoreIcon::cases()))
            ->update(['icon' => null]);

        Schema::table('chores', function (Blueprint $table) {
            $table->string('icon', 32)->nullable()->change();
        });
    }
};
