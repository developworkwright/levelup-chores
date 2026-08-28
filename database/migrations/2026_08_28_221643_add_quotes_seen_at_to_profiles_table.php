<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The marker behind the quote celebration cards, in the same shape as
 * `badges_seen_at`: everything that happened to this kid's quotes since this
 * stamp is news, and the shell moves the stamp once it has told them.
 *
 * One column covers both kinds of news — a quote added, and somebody reacting
 * to one of theirs — because they are the same question from the kid's side:
 * "what happened with the quotes while I wasn't looking?"
 *
 * Nullable, and null means *seed me without celebrating*. A household that has
 * been running for months would otherwise meet a kid with a queue of every
 * quote ever written down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->timestamp('quotes_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('quotes_seen_at');
        });
    }
};
