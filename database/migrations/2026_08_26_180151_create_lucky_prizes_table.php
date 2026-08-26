<?php

use App\Models\Household;
use App\Models\LuckyPrize;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Lucky Block's pool — a flat, parent-written list of things a kid can
 * win for three tickets.
 *
 * No cost column, and that absence is the feature. A Lucky Block prize needn't
 * exist in the Loot Shop or have a price: "Dad does your Saturday job" and
 * "you pick Friday's film" are worth having and worth nothing, so they can't
 * live in a points-priced catalog. Pricing them would also reintroduce the
 * tiers this design deliberately dropped — odds are flat, every active prize
 * is equally likely, and the pool's balance is therefore the parent's job
 * rather than the app's. See the parent screen's standing warning.
 *
 * `profile_id` is the scope: null means everyone. One pool with per-kid
 * scoping rather than N separate lists, so a household that wants the same
 * ten things for both kids writes them once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lucky_prizes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            // Null = every kid in the house can win it.
            $table->unsignedBigInteger('profile_id')->nullable()->index();
            $table->string('name');
            // The line under the prize on the reveal. Optional — the card
            // falls back to a fixed one, because a parent adding a prize in
            // ten seconds shouldn't have to write copy for it.
            $table->string('flavor')->nullable();
            // A Font Awesome class string, same free-set rule as chores.icon.
            $table->string('icon', 64)->nullable();
            // Only ever read for the icon's color. Filed from the name on the
            // way in, exactly as a store item is.
            $table->string('category')->nullable();
            $table->boolean('active')->default(true);
            // Cosmetic. Order does not affect odds and must never look like it
            // does — see the parent screen's drag handle.
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        // Households that already exist get the opening ten. New ones are
        // seeded by HouseholdFactory and DatabaseSeeder, which is the same
        // three-way split the bonus perk catalog uses.
        foreach (Household::all() as $household) {
            LuckyPrize::seedDefaults($household);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lucky_prizes');
    }
};
