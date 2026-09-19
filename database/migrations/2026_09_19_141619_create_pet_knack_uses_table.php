<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every time a kid's pet used its knack — see App\Services\KnackService.
 *
 * Counted per kid and per knack, not per pet: a kid with two Fetch pets gets
 * one Fetch a week, not two by swapping between them. `cosmetic_id` is which
 * pet did it, for the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_knack_uses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->unsignedBigInteger('profile_id');
            $table->unsignedBigInteger('cosmetic_id')->nullable();
            $table->string('knack', 32);
            $table->timestamp('used_at');
            $table->timestamps();

            $table->index(['profile_id', 'knack', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_knack_uses');
    }
};
