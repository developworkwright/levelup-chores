<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A parent pointing at one job: "this is the one that needs doing."
        // The stamp is when it was flagged, and it only binds for the household
        // day it lands in — so the flag lifts itself overnight the same way a
        // deadline does, and a parent has to re-assert what is actually urgent
        // rather than the board silently filling up with permanent flags.
        Schema::table('chores', function (Blueprint $table) {
            $table->timestamp('help_wanted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->dropColumn('help_wanted_at');
        });
    }
};
