<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A parent-set deadline: "get this done before I do it myself tonight."
        // Once it passes the chore drops off the board for the rest of the
        // household day, then comes back on its ordinary cadence tomorrow —
        // which is why the stamp only binds for the day it lands in rather
        // than needing to be cleared by anyone.
        Schema::table('chores', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
