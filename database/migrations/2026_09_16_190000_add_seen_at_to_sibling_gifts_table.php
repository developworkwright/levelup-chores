<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the recipient opened the gift row and saw who it was from.
 *
 * Null is "not seen yet", which is what lights the row's alert on Home. On the
 * gift row itself rather than a `gifts_seen_at` marker on profiles: a gift is
 * one kid's news, and a null here only ever means that one gift — there is no
 * first-deploy backlog to swallow, because a gift only shows on the day it was
 * given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sibling_gifts', function (Blueprint $table) {
            $table->timestamp('seen_at')->nullable()->after('gift_date');
        });
    }

    public function down(): void
    {
        Schema::table('sibling_gifts', function (Blueprint $table) {
            $table->dropColumn('seen_at');
        });
    }
};
