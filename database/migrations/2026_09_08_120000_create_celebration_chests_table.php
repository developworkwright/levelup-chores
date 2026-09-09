<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-off celebration days — the first day of school, and whatever comes next.
 *
 * A row is both halves of the card: what somebody said about the day, and the
 * chest they were handed for saying it. One row per kid per celebration.
 *
 * ## The answer never decides the payout
 *
 * `answer` is recorded and then never read by anything that pays. Every answer
 * opens the same chest for the same amount, including "rather not say" — a
 * chest that paid more for a good day would be buying a good answer, and the
 * kid this house is built around would give it one. See CelebrationService.
 *
 * The reward is stamped onto the row rather than looked up at read time,
 * because `points_per_dollar` is a household setting and a chest already opened
 * must keep reading back as what it actually paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('celebration_chests', function (Blueprint $table) {
            // Indexed, not constrained — see the bounties migration.
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->unsignedBigInteger('profile_id')->index();

            // Which celebration this is a row for — the keys in
            // CelebrationService::DAYS. A string rather than an enum so a
            // one-off day can be added and retired without a migration.
            $table->string('celebration_key', 64);

            // How the day went, in the day's own words. Nullable because the
            // row can exist for a kid who has opened nothing yet, and because
            // a celebration may not ask anything at all.
            $table->string('answer', 32)->nullable();

            // The optional longer version. Read by parents, never by siblings.
            $table->text('note')->nullable();

            $table->timestamp('answered_at')->nullable();

            // Null until the chest is opened; the reward columns fill in with
            // it, in one write.
            $table->timestamp('opened_at')->nullable();
            $table->unsignedInteger('reward_points')->default(0);
            $table->unsignedInteger('reward_tickets')->default(0);
            $table->unsignedInteger('reward_xp')->default(0);

            $table->timestamps();

            // One chest each. This is what makes answering idempotent and
            // opening safe against a double tap.
            $table->unique(['profile_id', 'celebration_key']);

            // How the parent console reads it: everyone's row for one day.
            $table->index(['household_id', 'celebration_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('celebration_chests');
    }
};
