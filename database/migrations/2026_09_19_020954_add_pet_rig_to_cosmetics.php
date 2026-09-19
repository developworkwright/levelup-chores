<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a pet's sheet is laid out, and where on it the toy goes.
 *
 * Pets are cut into eighteen poses now, not twelve. Six of them have empty
 * paws, and the app draws the toy into them, so the cut records where the
 * paws are in each age's play, toss and back cells — see
 * App\Services\CosmeticArt::anchors().
 *
 * Null for every pet made before the re-grid. Those keep their twelve-pose
 * sheets and keep working until a grown-up gives them new art. They are never
 * deleted, because kids own them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cosmetics', function (Blueprint $table) {
            $table->json('pet_rig')->nullable()->after('young_art_path');
        });
    }

    public function down(): void
    {
        Schema::table('cosmetics', function (Blueprint $table) {
            $table->dropColumn('pet_rig');
        });
    }
};
