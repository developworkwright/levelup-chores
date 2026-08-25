<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A link to the actual thing.
 *
 * Half of what a kid wants to know about a reward is what it looks like, and
 * a description can't carry that. When a kid asks for something specific — a
 * particular Lego set, a particular game — the fastest way to put it in the
 * shop is to paste the link they were already looking at, and the fastest way
 * for them to check it is the same link back.
 *
 * Nullable and expected to stay null on most rows: "Stay up an hour late" has
 * nothing to link to, and a shop where every card grows an empty button would
 * be worse than one with no links at all.
 *
 * Stored raw and sanitised on the way *in* rather than trusted on the way out
 * — see StoreItem::normalizeUrl(). The column is only ever written by a
 * parent, but it is rendered to a child as a live link, which is enough reason
 * to be strict about the scheme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_items', function (Blueprint $table) {
            $table->string('url', 2048)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('store_items', function (Blueprint $table) {
            $table->dropColumn('url');
        });
    }
};
