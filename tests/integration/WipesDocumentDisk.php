<?php

namespace Ramon\Verified\Tests\integration;

use Illuminate\Filesystem\Filesystem;

/**
 * O disco de documentos mora no tmp do flarum/testing e sobrevive entre os
 * testes. Limpa direto no sistema de arquivos: no `setUp` o app ainda não
 * pode subir (o banco não foi preparado).
 */
trait WipesDocumentDisk
{
    protected function wipeDocumentDisk(): void
    {
        (new Filesystem())->deleteDirectory(
            dirname(__DIR__, 2).'/vendor/flarum/testing/src/integration/tmp/storage/verified-documents'
        );
    }
}
