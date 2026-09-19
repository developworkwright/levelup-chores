<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Power Treats: bought with tickets and fed to a pet, for its knack — see
 * App\Services\KnackService.
 *
 * A treat for a knack that is spent is one more use of it, on top of the
 * week's own, banked until it is needed: `knack_use_id` is the use it paid
 * for, null while it is still waiting. A treat for an always-on knack doubles
 * it for the household day it was fed on, `day`, and is never "used".
 *
 * Kept per kid and per knack, like the uses themselves, so a treat bought for
 * one pet's Fetch waits for Fetch rather than going to whatever pet is out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_treats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->unsignedBigInteger('profile_id');
            $table->unsignedBigInteger('cosmetic_id')->nullable();
            $table->string('knack', 32);
            $table->unsignedSmallInteger('tickets_paid');
            $table->date('day');
            $table->unsignedBigInteger('knack_use_id')->nullable()->index();
            $table->timestamps();

            $table->index(['profile_id', 'knack']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_treats');
    }
};
