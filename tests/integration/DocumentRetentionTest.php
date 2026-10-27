<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Tests\integration;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Ramon\Verified\Documents\DocumentPathResolver;
use Ramon\Verified\Documents\DocumentRetention;
use Ramon\Verified\Job\PurgeOrphanedDocumentsJob;
use Ramon\Verified\Models\VerificationRequest;

/**
 * O que apaga arquivos de documentos: o sweep de órfãos e a purga da rotação.
 */
class DocumentRetentionTest extends TestCase
{
    use RetrievesAuthorizedUsers;
    use WipesDocumentDisk;

    private const REJECTED = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png';
    private const ORPHAN = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.png';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-verified');

        $this->wipeDocumentDisk();

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            VerificationRequest::class => [
                ['id' => 1, 'user_id' => 2, 'status' => 'rejected', 'document_path' => '/assets/verified/2/'.self::REJECTED, 'created_at' => Carbon::now()->subDay(), 'updated_at' => Carbon::now()->subDay(), 'handled_at' => Carbon::now()->subDay()],
            ],
        ]);
    }

    private function disk(): Filesystem
    {
        return $this->app()->getContainer()->make(Factory::class)->disk(DocumentPathResolver::DISK);
    }

    /** Grava um arquivo com mtime no passado (fora da janela de graça). */
    private function putOld(string $name, string $content = 'x'): void
    {
        $this->disk()->put("2/$name", $content);
        touch($this->disk()->path("2/$name"), time() - 2 * DocumentRetention::UNREFERENCED_GRACE_SECONDS);
    }

    /** No modo `keep`, o documento de uma solicitação rejeitada fica. */
    public function test_sweep_mantem_documento_de_rejeitada(): void
    {
        $this->setting('ramon-verified.document_retention', 'keep');
        $this->putOld(self::REJECTED);
        $this->putOld(self::ORPHAN);

        $this->app()->getContainer()->make(DocumentRetention::class)->sweepOrphans(2);

        $this->assertTrue($this->disk()->exists('2/'.self::REJECTED));
        $this->assertFalse($this->disk()->exists('2/'.self::ORPHAN));
    }

    /** O comando diário varre todos os usuários, não só quem envia de novo. */
    public function test_varredura_global_remove_orfaos(): void
    {
        $this->putOld(self::ORPHAN);

        $purged = $this->app()->getContainer()->make(DocumentRetention::class)->sweepAllOrphans();

        $this->assertSame(1, $purged);
        $this->assertFalse($this->disk()->exists('2/'.self::ORPHAN));
    }

    /** A purga da rotação poupa o que foi cifrado depois dela (chave nova). */
    public function test_rotacao_nao_apaga_arquivo_cifrado_com_a_chave_nova(): void
    {
        $this->disk()->put('2/'.self::REJECTED, "VENC1\nnovo");

        $this->app()->getContainer()->call([new PurgeOrphanedDocumentsJob(time() - 60), 'handle']);

        $this->assertTrue($this->disk()->exists('2/'.self::REJECTED));
        $this->assertNotNull(VerificationRequest::query()->find(1)->document_path);
    }

    public function test_rotacao_apaga_arquivo_cifrado_com_a_chave_antiga(): void
    {
        $this->putOld(self::REJECTED, "VENC1\nantigo");

        $this->app()->getContainer()->call([new PurgeOrphanedDocumentsJob(time()), 'handle']);

        $this->assertFalse($this->disk()->exists('2/'.self::REJECTED));
        $this->assertNull(VerificationRequest::query()->find(1)->document_path);
    }
}
