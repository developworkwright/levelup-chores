<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Native ENUM columns are MySQL-specific and painful to widen (and
        // SQLite, used in tests, has no native ENUM at all) — switch to a
        // plain string column instead. Valid values are already enforced
        // at the application layer by the ChoreCadence backed enum cast,
        // so the DB-level ENUM constraint was redundant anyway.
        Schema::table('chores', function (Blueprint $table) {
            $table->string('cadence', 20)->default('daily')->change();
        });
    }

    public function down(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->enum('cadence', ['daily', 'weekly'])->default('daily')->change();
        });
    }
};
