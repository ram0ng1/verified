<?php

declare(strict_types=1);

namespace Ramon\Verified\Tests\unit;

use Flarum\Foundation\Config;
use PHPUnit\Framework\TestCase;
use Ramon\Verified\Crypto\DocumentCipher;
use Ramon\Verified\Tests\Support\ArraySettings;

/**
 * A criptografia é uma escolha do admin (`encrypt_documents`), separada de
 * existir chave: ter a chave não liga, e ligar sem chave não grava em claro.
 */
final class DocumentCipherTest extends TestCase
{
    private function cipher(array $settings, array $config = []): DocumentCipher
    {
        return new DocumentCipher(new ArraySettings($settings), new Config($config + ['url' => 'http://localhost']));
    }

    public function test_desligada_por_padrao(): void
    {
        self::assertFalse($this->cipher([])->isEnabled());
    }

    public function test_chave_publica_sozinha_nao_liga(): void
    {
        $pair = sodium_crypto_box_keypair();
        $cipher = $this->cipher([DocumentCipher::SETTING_PUBLIC_KEY => base64_encode(sodium_crypto_box_publickey($pair))]);

        self::assertTrue($cipher->canEncrypt());
        self::assertFalse($cipher->isEnabled());
    }

    public function test_status_informa_a_escolha(): void
    {
        $status = $this->cipher([DocumentCipher::SETTING_ENABLED => '1'])->status();

        self::assertTrue($status['enabled']);
        self::assertFalse($status['has_public_key']);
    }

    public function test_cifra_e_decifra_com_o_par(): void
    {
        $pair = sodium_crypto_box_keypair();
        $cipher = $this->cipher(
            [DocumentCipher::SETTING_ENABLED => '1', DocumentCipher::SETTING_PUBLIC_KEY => base64_encode(sodium_crypto_box_publickey($pair))],
            [DocumentCipher::CONFIG_PRIVATE_KEY => base64_encode(sodium_crypto_box_secretkey($pair))]
        );

        $blob = $cipher->encrypt('documento');

        self::assertTrue(DocumentCipher::isEncryptedBlob($blob));
        self::assertSame('documento', $cipher->decryptIfEncrypted($blob));
    }
}
