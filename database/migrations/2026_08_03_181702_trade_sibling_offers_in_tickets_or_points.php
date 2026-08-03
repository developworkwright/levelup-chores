<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generalises a trade from "a favour for N points" into two sides: what the
 * sender gives and what they want back. Each side is points, tickets or a
 * favour, so "100 points for 2 tickets" is now expressible alongside the
 * favour trades the table was built for.
 *
 * `kind` goes away because it is derivable — the sender was "paying" exactly
 * when their side is a currency — and keeping a second, narrower answer to the
 * same question is how the two fall out of step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sibling_offers', function (Blueprint $table) {
            $table->string('give_asset')->nullable()->after('to_profile_id');
            $table->unsignedInteger('give_amount')->default(0)->after('give_asset');
            $table->string('get_asset')->nullable()->after('give_amount');
            $table->unsignedInteger('get_amount')->default(0)->after('get_asset');
        });

        // A favour side carries no amount, so the old `points` lands on
        // whichever side the sender was on and the other side stays at zero.
        DB::table('sibling_offers')->where('kind', 'paying')->update([
            'give_asset' => 'points',
            'give_amount' => DB::raw('points'),
            'get_asset' => 'favour',
        ]);

        DB::table('sibling_offers')->where('kind', 'earning')->update([
            'give_asset' => 'favour',
            'get_asset' => 'points',
            'get_amount' => DB::raw('points'),
        ]);

        Schema::table('sibling_offers', function (Blueprint $table) {
            $table->string('give_asset')->nullable(false)->change();
            $table->string('get_asset')->nullable(false)->change();
            // Null on a straight currency swap: there is no favour to describe.
            $table->string('description')->nullable()->change();
            $table->dropColumn(['kind', 'points']);
        });
    }

    public function down(): void
    {
        Schema::table('sibling_offers', function (Blueprint $table) {
            $table->string('kind')->nullable()->after('to_profile_id');
            $table->unsignedInteger('points')->default(0)->after('kind');
        });

        // A swap has no `kind` to go back to, so it collapses to the side the
        // sender was giving — the closest the old shape can represent.
        DB::table('sibling_offers')->where('give_asset', '!=', 'favour')->update([
            'kind' => 'paying',
            'points' => DB::raw('give_amount'),
        ]);

        DB::table('sibling_offers')->where('give_asset', 'favour')->update([
            'kind' => 'earning',
            'points' => DB::raw('get_amount'),
        ]);

        Schema::table('sibling_offers', function (Blueprint $table) {
            $table->string('kind')->nullable(false)->change();
            $table->string('description')->nullable(false)->default('')->change();
            $table->dropColumn(['give_asset', 'give_amount', 'get_asset', 'get_amount']);
        });
    }
};
