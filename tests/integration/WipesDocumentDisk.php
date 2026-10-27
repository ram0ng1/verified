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

use Illuminate\Filesystem\Filesystem;

/**
 * O disco de documentos mora no tmp do flarum/testing e sobrevive entre os
 * testes. Limpa direto no sistema de arquivos: no `setUp` o app ainda não
 * pode subir (o banco não foi preparado). O tmp vem de `tmpDir()`, que segue
 * o `FLARUM_TEST_TMP_DIR_LOCAL` do workflow do core.
 */
trait WipesDocumentDisk
{
    protected function wipeDocumentDisk(): void
    {
        (new Filesystem())->deleteDirectory($this->tmpDir().'/storage/verified-documents');
    }
}
