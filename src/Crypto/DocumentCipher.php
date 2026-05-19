<?php

namespace Ramon\Verified\Crypto;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use RuntimeException;

/**
 * Criptografia assimétrica para documentos de verificação usando "sealed
 * boxes" do libsodium (X25519 + XSalsa20-Poly1305). A chave pública vive em
 * setting `ramon-verified.encryption_public_key`; a chave privada é colada à
 * mão em `config.php` na entrada `verified-private-key`. Assim o processo
 * web não pode ser coagido a vazar uma chave que nunca recebeu.
 *
 * Layout em disco:  [MAGIC (6 bytes)] [sealed-box ciphertext].
 *
 * O prefixo MAGIC distingue blobs cifrados de arquivos legados em texto
 * claro — essencial no pipeline GDPR e em forums que ativam encryption no
 * meio do ciclo de vida.
 */
class DocumentCipher
{
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
     * `true` quando a pública derivada da privada bate com a pública
     * persistida; `false` se há mismatch (chave privada de outro par);
     * `null` quando faltam ambas — pergunta ainda não aplicável.
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

    /**
     * Decifrar exige par completo E que ambas as metades pertençam ao
     * mesmo par. Privada de outro par é tão útil quanto privada ausente.
     */
    public function canDecrypt(): bool
    {
        return $this->isAvailable()
            && $this->hasPublicKey()
            && $this->hasPrivateKey()
            && $this->keysMatch() === true;
    }

    /**
     * Cifra um buffer. Caller é responsável por já ter verificado que
     * encryption está disponível — caso contrário, lança.
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

        if (! is_string($sealed) || $sealed === '') {
            throw new RuntimeException('Document encryption failed (empty ciphertext).');
        }

        return self::MAGIC.$sealed;
    }

    /**
     * Decifra apenas se o buffer carrega o MAGIC. Blobs legados (não
     * cifrados) atravessam intactos — assim o pipeline de download
     * funciona uniformemente. Quem precisa do contrato estrito "tem que
     * ser cifrado" testa `isEncryptedBlob()` antes.
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
     * Gera um par X25519 novo. Pública é persistida; privada volta ao caller
     * para exibição única e colagem manual em `config.php`.
     *
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
     * Esquece a chave pública. Usado quando o admin reconhece perda da
     * privada — sem privada, manter pública só atrapalha (novos uploads
     * cifrariam para um par cujos arquivos cifrados são ilegíveis).
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
