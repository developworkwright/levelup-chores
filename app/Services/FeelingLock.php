<?php

namespace App\Services;

use RuntimeException;
use SensitiveParameter;

/**
 * Locks a feelings entry with the writer's own PIN.
 *
 * ## What this actually promises, and what it does not
 *
 * The app is run by a parent who owns the database, the server and this file.
 * No amount of cryptography makes an entry unreadable to somebody in that
 * position, and pretending otherwise to a kid who has been asked to trust it
 * would be worse than not offering a lock at all.
 *
 * What this buys is a different, real promise: reading a locked entry stops
 * being something that can happen by accident. Not from glancing at the
 * database while debugging, not from a stray query, not from an export, not
 * from a backup. It becomes a deliberate act of subverting your own app. The
 * honest sentence for a kid is "nobody opens this but you" — not "your parents
 * can't read this".
 *
 * ## The key is never stored
 *
 * It is derived from the PIN at the moment of locking, used, and discarded —
 * and derived again from the PIN at the moment of opening. It is deliberately
 * *not* kept in the session: this app's session driver is `database`, so a key
 * parked there would sit in the same database as the ciphertext it opens, which
 * is not a lock so much as a note saying where the key is.
 *
 * That also happens to be the feature. Typing the PIN *is* the act of locking,
 * and typing it again is the act of opening — which is the part a kid can
 * actually feel.
 *
 * ## The weak link is the PIN, not the cipher
 *
 * Argon2id is memory-hard and XChaCha20-Poly1305 is authenticated, so a wrong
 * PIN fails cleanly rather than returning plausible rubbish. But a four-digit
 * PIN is ten thousand guesses. At these limits that is a couple of hours of
 * someone's deliberate effort, not a wall. A longer PIN is the only thing that
 * changes that number, and it is worth offering to anybody who uses this.
 */
class FeelingLock
{
    /**
     * Argon2id cost. MODERATE rather than INTERACTIVE because locking happens a
     * handful of times a day at most, and every extra millisecond here is
     * multiplied by ten thousand for anybody guessing at a four-digit PIN.
     */
    private const OPS_LIMIT = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE;

    private const MEM_LIMIT = SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE;

    /** Bumped if the scheme ever changes, so old blobs stay readable. */
    private const VERSION = 'v1';

    /**
     * Encrypt `$plaintext` under `$pin`, returning the salt and the sealed blob.
     *
     * The salt is per entry rather than per person: two entries locked with the
     * same PIN produce unrelated keys, so cracking one reveals nothing about
     * the next.
     *
     * @return array{salt: string, sealed: string} both base64
     */
    public function seal(#[SensitiveParameter] string $pin, string $plaintext): array
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = $this->deriveKey($pin, $salt);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            // The version travels as associated data, so a blob written under
            // one scheme can never be silently opened as another.
            self::VERSION,
            $nonce,
            $key,
        );

        sodium_memzero($key);

        return [
            'salt' => base64_encode($salt),
            'sealed' => self::VERSION.':'.base64_encode($nonce.$cipher),
        ];
    }

    /**
     * Open a sealed blob, or null when the PIN is wrong.
     *
     * Null rather than an exception for a bad PIN: getting your own PIN wrong
     * is an ordinary thing to do and the card says so gently. Anything
     * genuinely malformed does throw, because that means the row is damaged
     * rather than the person mistyping.
     */
    public function open(#[SensitiveParameter] string $pin, string $salt, string $sealed): ?string
    {
        [$version, $payload] = array_pad(explode(':', $sealed, 2), 2, null);

        if ($version !== self::VERSION || $payload === null) {
            throw new RuntimeException('This locked entry was written by a version this app no longer understands.');
        }

        $raw = base64_decode($payload, true);
        $saltBytes = base64_decode($salt, true);

        if ($raw === false || $saltBytes === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new RuntimeException('This locked entry is damaged.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $key = $this->deriveKey($pin, $saltBytes);

        // Authenticated, so a wrong key fails here rather than handing back
        // convincing nonsense.
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, $version, $nonce, $key);

        sodium_memzero($key);

        return $plain === false ? null : $plain;
    }

    private function deriveKey(#[SensitiveParameter] string $pin, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $pin,
            $salt,
            self::OPS_LIMIT,
            self::MEM_LIMIT,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }
}
