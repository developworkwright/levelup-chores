<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            // At most one per household — first kid to complete it wins a
            // bonus; it's locked for everyone else until the cadence resets.
            $table->boolean('is_mystery')->default(false)->after('quest_eligible');
        });
    }

    public function down(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->dropColumn('is_mystery');
        });
    }
};
