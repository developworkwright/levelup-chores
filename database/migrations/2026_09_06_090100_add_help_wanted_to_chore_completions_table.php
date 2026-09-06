<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether this work was flagged Help Wanted when the kid took it,
        // frozen at claim for the same reason `struck_weak_point` is: the flag
        // is why they may have picked this chore over another, and a parent
        // clearing it an hour later must not reach back and cancel the ticket
        // the work had already earned.
        Schema::table('chore_completions', function (Blueprint $table) {
            $table->boolean('help_wanted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('chore_completions', function (Blueprint $table) {
            $table->dropColumn('help_wanted');
        });
    }
};
