<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a knack left behind when it was used, for the ones whose result
 * outlives the tap — Sniffer's list of the chores still in the running, which
 * the quest board reads for the rest of the day. See App\Services\KnackService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_knack_uses', function (Blueprint $table) {
            $table->json('payload')->nullable()->after('knack');
        });
    }

    public function down(): void
    {
        Schema::table('pet_knack_uses', function (Blueprint $table) {
            $table->dropColumn('payload');
        });
    }
};
