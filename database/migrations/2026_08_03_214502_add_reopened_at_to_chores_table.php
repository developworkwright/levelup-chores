<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            // When a parent last put this chore back up for grabs ahead of its
            // cadence. Every claim made before this instant stops holding the
            // chore, which is what lets "we only vacuum weekly, but someone
            // spilled chips" reopen a job without rewriting the completion that
            // has already paid out for it.
            $table->timestamp('reopened_at')->nullable()->after('used_at');
        });
    }

    public function down(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->dropColumn('reopened_at');
        });
    }
};
