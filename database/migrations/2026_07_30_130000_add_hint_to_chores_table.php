<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A parent-written clue for each chore. Only ever surfaced when a kid spends
 * tickets on a mystery hint, so it should read like a riddle rather than
 * naming the chore outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->string('hint')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->dropColumn('hint');
        });
    }
};
