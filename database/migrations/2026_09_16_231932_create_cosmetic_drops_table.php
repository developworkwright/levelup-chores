<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which limited items each week put on sale.
 *
 * Rotating stock needs no table: which of it is out is a pure function of the
 * week, so it can always be worked out again. Limiteds can't be, because a
 * limited is on sale for one week *ever* — and "has this one had its week yet"
 * is history, not arithmetic. A new upload changes the pool, and a pool that
 * changed must not bring back something that already retired.
 *
 * So the unique index is on household and item, not on the week: it is the
 * "never again" rule in the schema.
 *
 * Nothing schedules this. The week's drop is minted by the first page that
 * asks for it — see CosmeticService::limitedThisWeek().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cosmetic_drops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('cosmetic_id')->index();
            // ISO week, as "2026-W38".
            $table->string('week', 8);
            $table->timestamps();

            $table->unique(['household_id', 'cosmetic_id']);
            $table->index(['household_id', 'week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cosmetic_drops');
    }
};
