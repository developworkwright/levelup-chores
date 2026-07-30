<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            // The streak-milestone day (3/5/7/14/30) whose bonus has been
            // credited but not yet revealed via the chest-opening animation.
            $table->unsignedTinyInteger('pending_streak_chest')->nullable()->after('streak');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('pending_streak_chest');
        });
    }
};
