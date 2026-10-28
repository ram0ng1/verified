<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Crypto;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use RuntimeException;

/**
 * Sealed boxes do libsodium (X25519 + XSalsa20-Poly1305). A chave privada é
 * colada à mão no `config.php`, para o processo web nunca a receber e não poder
 * ser coagido a vazá-la.
 *
 * Layout em disco: [MAGIC (6 bytes)][ciphertext]. O MAGIC separa blobs cifrados
 * de arquivos em texto claro, gravados antes de a criptografia ser ligada.
 */
class DocumentCipher
{
    public const MAGIC = "VENC1\n";

    public const SETTING_PUBLIC_KEY = 'ramon-verified.encryption_public_key';
    public const SETTING_ENABLED = 'ramon-verified.encrypt_documents';
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

    /**
     * Ligada, um upload que não puder ser selado é recusado em vez de cair em texto
     * claro. Desligada, os já cifrados continuam legíveis enquanto houver a privada.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->settings->get(self::SETTING_ENABLED, false);
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
     * `null` quando falta alguma das chaves; `false` se a privada é de outro par.
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

    public function canEncrypt(): bool
    {
        return $this->isAvailable() && $this->hasPublicKey();
    }

    public function canDecrypt(): bool
    {
        return $this->isAvailable()
            && $this->hasPublicKey()
            && $this->hasPrivateKey()
            && $this->keysMatch() === true;
    }

    /**
     * Lança se a criptografia não estiver disponível.
     */
    public function encrypt(string $plaintext): string
    {
        $public = $this->loadPublicKey();
        if ($public === null) {
            throw new RuntimeException('No public key configured.');
        }

        try {
            $sealed = sodium_crypto_box_seal($plaintext, $public);
        } catch (\Throwable $e) {
            throw new RuntimeException('Document encryption failed.', 0, $e);
        }

        if ($sealed === '') {
            throw new RuntimeException('Document encryption failed (empty ciphertext).');
        }

        return self::MAGIC.$sealed;
    }

    /**
     * Blobs sem MAGIC passam intactos. Quem exige um blob cifrado testa
     * `isEncryptedBlob()` antes.
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
     * @return array{public: string, private: string} ambos em base64
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
     * Sem a privada, manter a pública faria novos uploads serem cifrados para um par
     * ilegível.
     */
    public function forgetPublicKey(): void
    {
        $this->settings->set(self::SETTING_PUBLIC_KEY, '');
    }

    public static function isEncryptedBlob(string $blob): bool
    {
        return str_starts_with($blob, self::MAGIC);
    }

    public function status(): array
    {
        $available  = $this->isAvailable();
        $hasPublic  = $this->hasPublicKey();
        $hasPrivate = $this->hasPrivateKey();
        $match      = ($hasPublic && $hasPrivate) ? $this->keysMatch() : null;

        $healthy = $available && $hasPublic && $hasPrivate && $match === true;

        return [
            'enabled'               => $this->isEnabled(),
            'available'             => $available,
            'has_public_key'        => $hasPublic,
            'private_key_present'   => $hasPrivate,
            'keys_match'            => $match,
            'healthy'               => $healthy,
            'requires_regeneration' => $available && $hasPublic && (! $hasPrivate || $match === false),
            'public_key'            => $hasPublic ? (string) $this->settings->get(self::SETTING_PUBLIC_KEY, '') : null,
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
