<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            // False keeps a chore off the bonus wheel — for opportunistic jobs
            // that only exist some days ("put the groceries away"), where a
            // multiplier would land on something nobody can actually do.
            // Separate from quest_eligible: a chore can be fine as an assigned
            // quest and still be a bad thing to gamble a 3x boost on.
            $table->boolean('wheel_eligible')->default(true)->after('quest_eligible');
        });
    }

    public function down(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->dropColumn('wheel_eligible');
        });
    }
};
