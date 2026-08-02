<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One-time chores aren't on a clock like the other cadences — they're
        // up for grabs until someone takes them, then gone until a parent puts
        // them back. This stamp is that switch: set when the chore is claimed,
        // cleared by a rejection or a parent reactivating it.
        Schema::table('chores', function (Blueprint $table) {
            $table->timestamp('used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->dropColumn('used_at');
        });
    }
};
