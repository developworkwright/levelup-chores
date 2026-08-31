<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of job a chore is — the side-quest board's chip row.
 *
 * This shipped for a day as a *derived* value with no column at all:
 * `chores.icon` already said "utensils", and a lookup turned that into
 * "Kitchen". That was wrong, and the reason is worth writing down. The icon is
 * chosen for a **card face** — a picture a kid who can't read the name picks a
 * chore by — and the column is deliberately uncast so a parent can paste any
 * Font Awesome class they like. Deriving the category off it made the picker
 * quietly do two jobs: a parent choosing a face they preferred could move a
 * chore to a different chip, or off every chip at once if the class was one no
 * preset owned. A category is not a collection of icon types.
 *
 * So it is a column a parent sets, and **nothing reads the icon at runtime any
 * more**. Nullable, with null meaning "nobody has said" — those chores collect
 * under Other, the same bucket that exists so a chore can never disappear off
 * the board when a chip is picked.
 *
 * **The backfill below is a one-off convenience, not a rule.** Without it every
 * existing chore would land in Other on deploy and the chip row would be three
 * words long until a parent went through the whole board. It runs the old
 * icon-to-category map exactly once, and that map is spelled out here rather
 * than read from the enum — the enum no longer has it, and a migration should
 * record what happened rather than call live code that can change underneath
 * it. `fa-tooth` is deliberately absent: it had a "Myself" category, that was
 * dropped for want of a single teeth chore anywhere, and guessing it into a
 * room or into cleaning would be inventing an answer the backfill doesn't have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->string('category', 16)->nullable()->after('effort');
        });

        $backfill = [
            'my-room' => ['fa-solid fa-bed', 'fa-solid fa-cubes'],
            'kitchen' => ['fa-solid fa-utensils', 'fa-solid fa-plate-wheat'],
            'cleaning' => ['fa-solid fa-broom', 'fa-solid fa-fan', 'fa-solid fa-window-maximize'],
            'laundry' => ['fa-solid fa-shirt'],
            'bins' => ['fa-solid fa-trash-can', 'fa-solid fa-recycle'],
            'garden' => ['fa-solid fa-seedling', 'fa-solid fa-droplet'],
            'car' => ['fa-solid fa-car'],
            'pets' => ['fa-solid fa-paw'],
            'errands' => ['fa-solid fa-envelope'],
        ];

        foreach ($backfill as $category => $classes) {
            DB::table('chores')->whereIn('icon', $classes)->update(['category' => $category]);
        }
    }

    public function down(): void
    {
        Schema::table('chores', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
