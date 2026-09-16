<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which row of Home's "Your day" this kid has open, and the household day that
 * answer belongs to.
 *
 * Two columns rather than one because "closed everything" and "has never
 * touched it" are different answers that both read as null: `home_day_open` is
 * the open row's key or null for none open, and `home_day_closed_on` says
 * whether that null was a decision. A row nobody has closed today starts open,
 * so without the date every kid would arrive at a shut column every morning.
 *
 * Not Alpine state and not the session: the day survives `wire:navigate`
 * between kid pages, and a kid on a tablet and the same kid on a phone should
 * find the same column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('home_day_open', 32)->nullable()->after('powered_up_on');
            $table->date('home_day_closed_on')->nullable()->after('home_day_open');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['home_day_open', 'home_day_closed_on']);
        });
    }
};
