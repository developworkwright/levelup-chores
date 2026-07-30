<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            // Only relevant when is_mystery is true — paid on top of the
            // chore's normal points, kept in a separate field so the chore's
            // listed points don't give away which one it is.
            $table->unsignedInteger('mystery_bonus_points')->default(500)->after('is_mystery');
        });
    }

    public function down(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->dropColumn('mystery_bonus_points');
        });
    }
};
