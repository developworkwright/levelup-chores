<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            // Which monster this kid aimed at last, so the picker opens on it
            // rather than making them find their answer again every time. A
            // preference, not a commitment — every claim still asks.
            $table->unsignedTinyInteger('last_monster_tier')->nullable()->after('goal_contribution');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('last_monster_tier');
        });
    }
};
