<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            // False excludes a chore from random daily-quest assignment —
            // for chores with prerequisites (e.g. mopping needs sweeping first).
            $table->boolean('quest_eligible')->default(true)->after('min_age');
        });
    }

    public function down(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->dropColumn('quest_eligible');
        });
    }
};
