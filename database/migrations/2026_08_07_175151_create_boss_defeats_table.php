<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boss_defeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();

            // The skin *and* its name are both stored: a defeat is history, and
            // history must not change shape because the enum was later renamed.
            $table->string('boss_key');
            $table->string('boss_name');

            // The household's battle counter at the time. Doubles as the guard
            // against banking the same kill twice.
            $table->unsignedInteger('battle')->default(1);

            // The goal this battle was fought over — its size, and what the
            // family was actually saving for.
            $table->unsignedInteger('health');
            $table->string('goal_name')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('defeated_at');

            // Nullable so a profile can be deleted without taking the trophy
            // shelf with it.
            $table->foreignId('finisher_profile_id')->nullable()->constrained('profiles')->nullOnDelete();

            // Frozen leaderboard: [['name' => 'Nova', 'points' => 420], ...].
            // A snapshot rather than a join, because starting the next goal
            // zeroes every kid's goal_contribution — the live numbers are gone
            // by the time anyone looks back at this row.
            $table->json('contributions');

            $table->timestamps();

            $table->index(['household_id', 'defeated_at']);
            $table->unique(['household_id', 'battle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boss_defeats');
    }
};
