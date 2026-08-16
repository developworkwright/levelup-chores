<?php

use App\Enums\PerkEffect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per night answered.
 *
 * This is the *record*, not the score. The counters on `profiles` are the
 * score, and a parent can nudge those by hand — so the two are allowed to
 * disagree and nothing recomputes one from the other. What the log is for is
 * the three things a bare counter can't do: stop a night being answered twice,
 * give the Night Saver a specific night to buy back, and let a parent see the
 * pattern rather than just the total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sleep_nights', function (Blueprint $table) {
            // Indexed, not constrained — see the bounties migration.
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->unsignedBigInteger('profile_id')->index();

            // The household day the night belongs to. The day rolls at
            // `day_boundary_hour` (4am by default), so a kid answering at
            // breakfast is answering for the night that just ended, and the
            // date they answer on is the date the night is filed under.
            $table->date('night_date');

            // own_bed | visited | rough — all three are answers, and only the
            // first lights a star. See App\Enums\SleepOutcome.
            $table->string('outcome');

            // Set when a Night Saver was spent to turn a miss back into a kept
            // run. The outcome stays honest; this records that it was bought.
            $table->timestamp('saved_at')->nullable();

            $table->timestamps();

            // One answer per kid per night, which is what makes the card
            // idempotent without the page having to remember anything.
            $table->unique(['profile_id', 'night_date']);
        });

        // Households that already exist get the Night Saver too —
        // PerkEffect::defaults() only covers ones created from here on, via the
        // seeder and the factory.
        $defaults = PerkEffect::NightSaver->defaults();

        $rows = DB::table('households')->pluck('id')->map(fn (int $id) => [
            'household_id' => $id,
            'effect' => PerkEffect::NightSaver->value,
            'name' => $defaults['name'],
            'description' => $defaults['description'],
            'cost' => $defaults['cost'],
            'glyph' => $defaults['glyph'],
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($rows !== []) {
            DB::table('bonus_perks')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sleep_nights');
    }
};
