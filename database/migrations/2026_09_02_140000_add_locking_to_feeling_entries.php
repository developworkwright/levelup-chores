<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Locking a reason with the writer's own PIN.
 *
 * ## Only what somebody chose to lock
 *
 * The plaintext `because` stays exactly where it was. Locking is a deliberate
 * act on one entry, and the sealed text goes in a column of its own — so
 * exactly one of `because` and `because_locked` is ever set, the same either/or
 * shape `sleep_nights` uses for outcome/minutes.
 *
 * That is not tidiness. A PIN reset makes every locked entry unopenable
 * forever, and scoping the damage to the handful of entries somebody
 * deliberately locked — rather than to their whole history — is the difference
 * between a bad afternoon and losing the lot.
 *
 * ## No key column, on purpose
 *
 * There is nowhere here to put a key and there never should be. It is derived
 * from the PIN at the moment of locking and again at the moment of opening, and
 * discarded both times. `lock_salt` is public by design: a salt is not a secret,
 * it exists so that two entries locked with the same PIN produce unrelated
 * keys. See App\Services\FeelingLock for what this does and does not promise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feeling_entries', function (Blueprint $table) {
            // The sealed reason: "v1:" and then base64 of nonce + ciphertext.
            $table->text('because_locked')->nullable()->after('because');

            // Per entry, not per person. Base64 of 16 random bytes.
            $table->string('lock_salt', 32)->nullable()->after('because_locked');

            // When it was locked. Also the flag the card reads, so "is this
            // locked" never has to be inferred from whether a text column
            // happens to be populated.
            $table->timestamp('locked_at')->nullable()->after('lock_salt');
        });
    }

    public function down(): void
    {
        Schema::table('feeling_entries', function (Blueprint $table) {
            $table->dropColumn(['because_locked', 'lock_salt', 'locked_at']);
        });
    }
};
