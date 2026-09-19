<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The weekly board pays tokens now, not tickets.
 *
 * `tokens` is what the week paid and `paid_profile_id` is who got it. That is
 * not always `profile_id`: a grown-up who tops the week still wins it (the
 * board and "last champion" say so), and the tokens go to the best-placed kid
 * below them instead.
 *
 * `seen_at` is when the kid who was paid saw the win announced on the arcade
 * page. Every row already here is stamped now, so the first visit after the
 * deploy does not announce a win from weeks ago — and so the announcement for
 * this week's win is not swallowed either, which is what a null marker left
 * alone would do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arcade_week_prizes', function (Blueprint $table) {
            $table->unsignedSmallInteger('tokens')->default(0);
            $table->unsignedBigInteger('paid_profile_id')->nullable()->index();
            $table->timestamp('seen_at')->nullable();
        });

        DB::table('arcade_week_prizes')->whereNull('seen_at')->update(['seen_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('arcade_week_prizes', function (Blueprint $table) {
            $table->dropColumn(['tokens', 'paid_profile_id', 'seen_at']);
        });
    }
};
