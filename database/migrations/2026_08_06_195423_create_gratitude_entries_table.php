<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gratitude quest: three things a kid is grateful for, once a household-day,
 * paid in bonus tickets.
 *
 * The rows are kept rather than just the payout, because reading them back is
 * the point — the tickets are only what gets a kid to sit down and write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gratitude_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            // One row holds all three answers: they were written together and
            // they're always read back together.
            $table->json('items');
            $table->timestamp('created_at');

            // The once-a-day rule, enforced where it can't be raced. The service
            // checks first for a civil refusal; this is the backstop.
            $table->unique(['profile_id', 'entry_date']);
            $table->index(['household_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gratitude_entries');
    }
};
