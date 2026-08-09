<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bounties', function (Blueprint $table) {
            // Plain indexed columns rather than foreign keys throughout: the
            // constraints get in the way of fixing data by hand later, which is
            // a thing that actually happens to this app. Indexed because every
            // lookup here is by household or by one of the two kids.
            $table->id();
            $table->unsignedBigInteger('household_id')->index();
            $table->unsignedBigInteger('poster_profile_id')->index();

            // Which way the deal runs: the poster either pays for work or does
            // it. Everything else about the row is read through this.
            $table->string('kind');

            $table->string('reward_asset');
            $table->unsignedInteger('reward_amount');

            // The job itself. Always free text — board chores already pay
            // household points and their cooldowns are household-wide, so
            // bidding on one buys nothing anybody couldn't already have.
            $table->string('description');

            $table->string('status')->index();

            $table->unsignedBigInteger('claimed_by_profile_id')->nullable()->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamp('settled_at')->nullable();

            // A parent taking an offer of work does not pay from a balance —
            // they mint a one-time chore at the agreed price, and this is it.
            // Points earned that way then run the ordinary approval path.
            $table->unsignedBigInteger('hired_chore_id')->nullable();

            // Three clocks, because there are three ways a deal stalls: nobody
            // takes it, the taker goes quiet, or the payer never answers.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('auto_release_at')->nullable();

            $table->timestamps();

            $table->index(['household_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bounties');
    }
};
