<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_quests', function (Blueprint $table) {
            $table->timestamp('revealed_at')->nullable()->after('chore_id');
        });
    }

    public function down(): void
    {
        Schema::table('daily_quests', function (Blueprint $table) {
            $table->dropColumn('revealed_at');
        });
    }
};
