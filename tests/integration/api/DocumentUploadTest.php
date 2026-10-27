<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Filesystem\Factory;
use Laminas\Diactoros\UploadedFile;
use Ramon\Verified\Crypto\DocumentCipher;
use Ramon\Verified\Documents\DocumentPathResolver;
use Ramon\Verified\Documents\DocumentRetention;
use Ramon\Verified\Tests\integration\WipesDocumentDisk;

/**
 * `POST /api/verified/documents` com a escolha de criptografar ou não.
 */
class DocumentUploadTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use WipesDocumentDisk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-verified');

        $this->wipeDocumentDisk();

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
        ]);
    }

    private function upload(): \Psr\Http\Message\ResponseInterface
    {
        $path = tempnam(sys_get_temp_dir(), 'vdoc');
        $image = imagecreatetruecolor(4, 4);
        imagepng($image, $path);

        $file = new UploadedFile($path, (int) filesize($path), UPLOAD_ERR_OK, 'rg.png', 'image/png');

        return $this->send(
            $this->request('POST', '/api/verified/documents', ['authenticatedAs' => 2])
                ->withUploadedFiles(['document' => $file])
        );
    }

    private function storedFile(\Psr\Http\Message\ResponseInterface $response): string
    {
        $token = json_decode((string) $response->getBody(), true)['documentPath'];
        $disk = $this->app()->getContainer()->make(Factory::class)->disk(DocumentPathResolver::DISK);

        return (string) $disk->get('2/'.basename($token));
    }

    private function publicKey(): string
    {
        return base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair()));
    }

    public function test_ligada_sem_chave_recusa_em_vez_de_gravar_em_claro(): void
    {
        $this->setting(DocumentCipher::SETTING_ENABLED, '1');

        $response = $this->upload();

        $this->assertEquals(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('ramon-verified.api.encryption.not_configured', (string) $response->getBody());
    }

    public function test_ligada_com_chave_grava_cifrado(): void
    {
        $this->setting(DocumentCipher::SETTING_ENABLED, '1');
        $this->setting(DocumentCipher::SETTING_PUBLIC_KEY, $this->publicKey());

        $response = $this->upload();

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertTrue(DocumentCipher::isEncryptedBlob($this->storedFile($response)));
    }

    /** Ter a chave não basta: com a opção desligada o arquivo vai em claro. */
    public function test_desligada_grava_em_claro_mesmo_com_chave(): void
    {
        $this->setting(DocumentCipher::SETTING_ENABLED, '0');
        $this->setting(DocumentCipher::SETTING_PUBLIC_KEY, $this->publicKey());

        $response = $this->upload();

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringStartsWith("\x89PNG", $this->storedFile($response));
    }

    /** Uploads sem solicitação têm teto: antes, enchiam o disco sem limite. */
    public function test_limita_uploads_soltos_por_usuario(): void
    {
        for ($i = 0; $i < DocumentRetention::MAX_UNATTACHED_PER_USER; $i++) {
            $r = $this->upload();
            $this->assertEquals(200, $r->getStatusCode(), (string) $r->getBody());
        }

        $response = $this->upload();

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertStringContainsString('ramon-verified.api.too_many_uploads', (string) $response->getBody());
    }
}
