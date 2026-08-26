<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one pool-level rule the Lucky Block has: whether a prize a kid has won
 * but not yet been handed drops out of their next draw.
 *
 * Off by default, so the block starts out rolling against the whole pool and
 * repeats are simply possible. A household that finds the same prize coming up
 * too often turns it on from the Lucky Block screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->boolean('lucky_hold_won')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('lucky_hold_won');
        });
    }
};
