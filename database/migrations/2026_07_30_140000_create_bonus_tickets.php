<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bonus tickets: the spendable currency minted by XP, without ever spending
 * the XP itself. One ticket per level crossed, one per badge unlocked.
 *
 * tickets_granted_through_level is a high-water mark, not a running total.
 * XP can go down (quest:reset-today claws back 25 per undone approval), so
 * without it a kid could dip below a threshold and re-mint the same ticket on
 * the way back up.
 */
return new class extends Migration
{
    /**
     * Snapshot of Profile::XP_PER_LEVEL at the time of writing. Deliberately
     * hardcoded — this migration should keep reproducing the same result even
     * if the curve is retuned later.
     */
    private const XP_PER_LEVEL = 200;

    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->unsignedInteger('bonus_tickets')->default(0)->after('xp');
            $table->unsignedInteger('tickets_granted_through_level')->default(1)->after('bonus_tickets');
        });

        Schema::create('bonus_ticket_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            // Plain string rather than a native enum, so adding a kind later
            // doesn't need a migration and SQLite stays happy in tests.
            $table->string('kind');
            $table->integer('amount');
            $table->string('description');
            $table->nullableMorphs('related');
            $table->timestamp('created_at');

            $table->index(['profile_id', 'created_at']);
        });

        $this->grantForProgressAlreadyEarned();
    }

    public function down(): void
    {
        Schema::dropIfExists('bonus_ticket_entries');

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['bonus_tickets', 'tickets_granted_through_level']);
        });
    }

    /**
     * Existing kids have already earned levels and badges, so hand out what
     * they'd have accumulated rather than starting everyone at zero.
     */
    private function grantForProgressAlreadyEarned(): void
    {
        $now = now();

        $profiles = DB::table('profiles')->where('role', 'kid')->get(['id', 'household_id', 'name', 'xp']);

        foreach ($profiles as $profile) {
            $level = 1 + intdiv((int) $profile->xp, self::XP_PER_LEVEL);
            $fromLevels = $level - 1;
            $fromBadges = DB::table('profile_badges')->where('profile_id', $profile->id)->count();

            $total = $fromLevels + $fromBadges;

            DB::table('profiles')->where('id', $profile->id)->update([
                'bonus_tickets' => $total,
                'tickets_granted_through_level' => $level,
            ]);

            if ($total > 0) {
                DB::table('bonus_ticket_entries')->insert([
                    'household_id' => $profile->household_id,
                    'profile_id' => $profile->id,
                    'kind' => 'adjustment',
                    'amount' => $total,
                    'description' => "Starting tickets — level {$level} and {$fromBadges} badge(s) already earned",
                    'related_type' => null,
                    'related_id' => null,
                    'created_at' => $now,
                ]);
            }
        }
    }
};
