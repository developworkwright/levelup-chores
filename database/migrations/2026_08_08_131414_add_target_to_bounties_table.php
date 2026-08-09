<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A job can now be aimed at one sibling instead of the whole board.
 *
 * This is what lets "pay Nova to do the dishes" and "pay anyone to do the
 * dishes" be the same thing with the target set or not. Both then run the one
 * claim → done → confirm cycle, where previously the directed version was a
 * sibling trade that paid the moment it was accepted — before any work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bounties', function (Blueprint $table) {
            // Null means the open board. Indexed, not constrained — see the
            // create migration.
            $table->unsignedBigInteger('target_profile_id')->nullable()->after('poster_profile_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('bounties', function (Blueprint $table) {
            $table->dropColumn('target_profile_id');
        });
    }
};
