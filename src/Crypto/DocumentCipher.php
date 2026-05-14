<?php

namespace Ramon\Verified\Crypto;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use RuntimeException;

/**
 * Asymmetric encryption for verification documents.
 *
 * Uses libsodium "sealed boxes" (X25519 + XSalsa20-Poly1305): the public key
 * is enough to encrypt; only the matching private key can decrypt. This
 * matches the trust model the user asked for — the admin generates the
 * keypair once and pastes the private key into config.php BY HAND, so the
 * web process can't be coerced into leaking a key it has never been told.
 *
 * Key location:
 *   - Public key  → setting `ramon-verified.encryption_public_key` (base64)
 *   - Private key → config.php under `verified-private-key` (base64)
 *
 * On-disk file layout for an encrypted document:
 *   [MAGIC (6 bytes)] [sealed-box ciphertext]
 *
 * The MAGIC prefix lets us tell encrypted files apart from legacy
 * unencrypted ones — important for the GDPR pipeline and for forums that
 * enable encryption mid-life-cycle.
 *
 * libsodium is bundled with PHP 8.1+ (Flarum 2's minimum), so we don't
 * fall back to OpenSSL. Calling code can rely on `isAvailable()` to short
 * circuit gracefully if a hardened build dropped sodium.
 */
class DocumentCipher
{
    /** Header bytes — version-tagged so we can roll the format later. */
    public const MAGIC = "VENC1\n";

    public const SETTING_PUBLIC_KEY = 'ramon-verified.encryption_public_key';
    public const CONFIG_PRIVATE_KEY = 'verified-private-key';

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Config $config
    ) {
    }

    public function isAvailable(): bool
    {
        return function_exists('sodium_crypto_box_seal')
            && function_exists('sodium_crypto_box_seal_open');
    }

    public function hasPublicKey(): bool
    {
        return $this->loadPublicKey() !== null;
    }

    public function hasPrivateKey(): bool
    {
        return $this->loadPrivateKey() !== null;
    }

    /**
     * Both keys are present AND the public key derived from the private
     * key actually matches the stored public key. A mismatch means the
     * admin pasted a private key from a *different* keypair into
     * config.php — encryption looks healthy but every decrypt would fail.
     *
     * Returns null when either key is missing (the question doesn't
     * apply yet); true / false when both are present.
     */
    public function keysMatch(): ?bool
    {
        $public  = $this->loadPublicKey();
        $secret  = $this->loadPrivateKey();
        if ($public === null || $secret === null) return null;

        $derived = sodium_crypto_box_publickey_from_secretkey($secret);
        $match   = hash_equals($public, $derived);

        sodium_memzero($secret);

        return $match;
    }

    /**
     * The system can encrypt new uploads. Requires a public key configured
     * in settings — the private key isn't needed to encrypt.
     */
    public function canEncrypt(): bool
    {
        return $this->isAvailable() && $this->hasPublicKey();
    }

    /**
     * The system can decrypt existing files. Requires both halves of the
     * keypair AND the two halves to actually belong to the same pair —
     * a mismatched private key is no better than a missing one.
     */
    public function canDecrypt(): bool
    {
        return $this->isAvailable()
            && $this->hasPublicKey()
            && $this->hasPrivateKey()
            && $this->keysMatch() === true;
    }

    /**
     * Encrypt a buffer. Caller is expected to have already validated
     * that encryption is available — we throw if not.
     */
    public function encrypt(string $plaintext): string
    {
        $public = $this->loadPublicKey();
        if ($public === null) {
            throw new RuntimeException('No public key configured.');
        }

        // Defensive: in modern PHP, sodium_crypto_box_seal throws SodiumException
        // on invalid input, but older builds and patched runtimes have been
        // observed to return false instead. Either way, we must not silently
        // persist a corrupted "encrypted" blob.
        try {
            $sealed = sodium_crypto_box_seal($plaintext, $public);
        } catch (\Throwable $e) {
            throw new RuntimeException('Document encryption failed.', 0, $e);
        }

        if (!is_string($sealed) || $sealed === '') {
            throw new RuntimeException('Document encryption failed (empty ciphertext).');
        }

        return self::MAGIC.$sealed;
    }

    /**
     * Decrypt a buffer if and only if it carries our MAGIC header. Legacy
     * unencrypted buffers (uploaded before encryption was enabled) are
     * returned untouched so downloads keep working through the same
     * pipeline. Callers that need a strict "must have been encrypted"
     * contract should branch on `isEncryptedBlob(...)` before calling.
     */
    public function decryptIfEncrypted(string $blob): string
    {
        if (! self::isEncryptedBlob($blob)) {
            return $blob;
        }

        $secret = $this->loadPrivateKey();
        $public = $this->loadPublicKey();
        if ($secret === null || $public === null) {
            throw new RuntimeException('Private or public key not available.');
        }

        $body = substr($blob, strlen(self::MAGIC));
        $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, $public);

        $plaintext = sodium_crypto_box_seal_open($body, $keypair);
        sodium_memzero($keypair);
        sodium_memzero($secret);

        if ($plaintext === false) {
            throw new RuntimeException('Document decryption failed (key mismatch or corrupted file).');
        }

        return $plaintext;
    }

    /**
     * Backwards-compatible alias. Prefer `decryptIfEncrypted(...)` in new
     * call sites — the original `decrypt(...)` name is misleading because
     * legacy unencrypted blobs flow through verbatim (audit F15).
     *
     * @deprecated 2.0.18 use {@see decryptIfEncrypted()}.
     */
    public function decrypt(string $blob): string
    {
        return $this->decryptIfEncrypted($blob);
    }

    /**
     * Generate a fresh X25519 keypair. Public is persisted as a setting
     * here; private is returned to the caller so it can be displayed once
     * and instructed for manual config.php placement.
     *
     * @return array{public: string, private: string} both base64-encoded
     */
    public function generateKeypair(): array
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('libsodium is not available on this server.');
        }

        $keypair = sodium_crypto_box_keypair();
        $public  = sodium_crypto_box_publickey($keypair);
        $secret  = sodium_crypto_box_secretkey($keypair);

        $publicB64  = base64_encode($public);
        $privateB64 = base64_encode($secret);

        $this->settings->set(self::SETTING_PUBLIC_KEY, $publicB64);

        sodium_memzero($keypair);
        sodium_memzero($secret);

        return [
            'public'  => $publicB64,
            'private' => $privateB64,
        ];
    }

    /**
     * Forget the public key. Used when the admin acknowledges that the
     * private key was lost — no point keeping a public key around when no
     * existing files can be decrypted, and any new uploads should be
     * encrypted to the *new* keypair.
     */
    public function forgetPublicKey(): void
    {
        $this->settings->set(self::SETTING_PUBLIC_KEY, '');
    }

    public static function isEncryptedBlob(string $blob): bool
    {
        return str_starts_with($blob, self::MAGIC);
    }

    /**
     * Cheap header probe — reads only the first bytes of a file, no full
     * load. Used by the GDPR export and the download streamer to decide
     * whether a file needs decryption.
     */
    public static function isEncryptedFile(string $absolutePath): bool
    {
        if (! is_file($absolutePath)) return false;

        $fh = @fopen($absolutePath, 'rb');
        if ($fh === false) return false;

        $head = fread($fh, strlen(self::MAGIC));
        fclose($fh);

        return $head === self::MAGIC;
    }

    public function status(): array
    {
        $available  = $this->isAvailable();
        $hasPublic  = $this->hasPublicKey();
        $hasPrivate = $this->hasPrivateKey();
        $match      = ($hasPublic && $hasPrivate) ? $this->keysMatch() : null;

        $healthy = $available && $hasPublic && $hasPrivate && $match === true;

        return [
            'available'           => $available,
            'has_public_key'      => $hasPublic,
            'private_key_present' => $hasPrivate,
            'keys_match'          => $match, // null when either key missing, otherwise bool
            'healthy'             => $healthy,
            // requires_regeneration covers BOTH "private key missing" and
            // "private key present but from the wrong keypair" — in either
            // case existing encrypted documents are unreadable and the
            // admin needs to either restore the right private key or
            // rotate (which wipes the encrypted documents).
            'requires_regeneration' => $available && $hasPublic && (! $hasPrivate || $match === false),
            // Public key is non-secret — exposing it lets the admin verify
            // the value matches what they expect (and copy it for backups
            // or external tooling that needs to encrypt to the same pair).
            'public_key'          => $hasPublic ? (string) $this->settings->get(self::SETTING_PUBLIC_KEY, '') : null,
        ];
    }

    private function loadPublicKey(): ?string
    {
        $raw = $this->settings->get(self::SETTING_PUBLIC_KEY, '');
        if (! is_string($raw) || $raw === '') return null;

        $decoded = base64_decode($raw, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            return null;
        }

        return $decoded;
    }

    private function loadPrivateKey(): ?string
    {
        $raw = $this->config[self::CONFIG_PRIVATE_KEY] ?? null;
        if (! is_string($raw) || $raw === '') return null;

        $decoded = base64_decode($raw, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            return null;
        }

        return $decoded;
    }
}
