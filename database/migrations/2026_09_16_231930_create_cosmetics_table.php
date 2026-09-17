<?php

use App\Models\Cosmetic;
use App\Models\Household;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cosmetic locker's catalog: frames, avatars, name plates, themes, home
 * patterns, arcade cabinets and tap effects, bought with tickets.
 *
 * The first thing tickets buy that doesn't get used up. Every other ticket sink
 * is consumable — a perk is spent, the wheel eats a charge — so a kid holding
 * thirty tickets had exactly the same app as one holding three.
 *
 * **Most items are not files.** A catalog item is a recipe name plus a price:
 * `recipe` names a drawing in resources/js/cosmetics.js, which generates the
 * art. A grown-up can also upload a picture from the parent console, and that
 * row carries `art_path` instead. Whichever is set is what gets drawn.
 *
 * `published_at` null is a draft, which no kid can see. `pulled_at` stops an
 * item being sold without taking it off anybody already wearing it — which is
 * why there is no deleting a published item at all.
 *
 * Seeded per household for the same reason the bonus perks are: a grown-up
 * pulling an item from stock is a decision about their own house.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cosmetics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->string('slot', 16);
            $table->string('recipe', 40)->nullable();
            $table->string('art_path')->nullable();
            $table->string('name', 60);
            $table->unsignedSmallInteger('cost');
            $table->string('stock', 16);
            $table->string('flavor', 16)->nullable();
            $table->string('motion', 16)->nullable();
            // What the upload checks said, so the drafts list can say it again
            // without decoding the picture on every render.
            $table->json('checks')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('pulled_at')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'slot']);
        });

        foreach (Household::all() as $household) {
            Cosmetic::seedDefaults($household);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cosmetics');
    }
};
