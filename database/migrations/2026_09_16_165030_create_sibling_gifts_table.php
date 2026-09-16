<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily gift: once a household day, every kid picks a sibling to hand a
 * ticket to.
 *
 * The house mints the ticket rather than the giver paying it — a gift that
 * cost the giver would never leave the pocket of the kid who is saving, and the
 * only way to lose this one is not opening the app.
 *
 * The unique index is the once-a-day rule in the schema, so a double tap lands
 * on a constraint rather than on a second ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sibling_gifts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->unsignedBigInteger('giver_id')->index();
            $table->unsignedBigInteger('recipient_id')->index();
            $table->date('gift_date');
            $table->timestamps();

            $table->unique(['giver_id', 'gift_date']);
            $table->index(['recipient_id', 'gift_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sibling_gifts');
    }
};
