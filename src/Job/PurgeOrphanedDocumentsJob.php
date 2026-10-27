<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Job;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Psr\Log\LoggerInterface;
use Ramon\Verified\Crypto\DocumentCipher;
use Ramon\Verified\Documents\DocumentPathResolver;
use Ramon\Verified\Models\VerificationRequest;
use Throwable;

/**
 * Apaga os arquivos cifrados com a chave antiga após uma rotação. A pública nova
 * já existe quando o job roda e o blob não guarda a impressão da chave, então só
 * saem arquivos gravados antes de `$rotatedAt`.
 *
 * Roda em fila para não prender o request do admin numa varredura completa que
 * pode estourar o `request_terminate_timeout`.
 */
class PurgeOrphanedDocumentsJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;

    public int $tries = 1;

    public int $timeout = 1800;

    /**
     * @param int $rotatedAt timestamp Unix capturado antes de a chave nova
     *                       existir; arquivos mais novos ficam
     */
    public function __construct(
        public int $rotatedAt
    ) {
    }

    public function handle(
        Factory $filesystem,
        DocumentPathResolver $resolver,
        LoggerInterface $logger
    ): void {
        $disk = $filesystem->disk(DocumentPathResolver::DISK);
        $purged = 0;
        $failed = 0;

        /*
         * `chunkById`: o callback zera `document_path`, a coluna do `whereNotNull`, e a
         * paginação por OFFSET pularia linhas.
         */
        VerificationRequest::query()
            ->whereNotNull('document_path')
            ->chunkById(200, function ($rows) use ($disk, $resolver, $logger, &$purged, &$failed) {
                foreach ($rows as $row) {
                    $relative = $resolver->resolveRelative(
                        (string) $row->document_path,
                        (int) $row->user_id
                    );
                    if ($relative === null || ! $disk->exists($relative)) {
                        continue;
                    }

                    try {
                        if ($disk->lastModified($relative) >= $this->rotatedAt) {
                            continue;
                        }
                    } catch (Throwable $e) {
                        $failed++;
                        continue;
                    }

                    try {
                        $stream = $disk->readStream($relative);
                    } catch (Throwable $e) {
                        $failed++;
                        continue;
                    }
                    if (! is_resource($stream)) {
                        $failed++;
                        continue;
                    }
                    $magic = fread($stream, strlen(DocumentCipher::MAGIC));
                    fclose($stream);
                    if ($magic === false || ! DocumentCipher::isEncryptedBlob($magic)) {
                        continue;
                    }

                    try {
                        $deleted = $disk->delete($relative);
                    } catch (Throwable $e) {
                        $deleted = false;
                    }

                    if ($deleted) {
                        $row->document_path = null;
                        $row->save();
                        $purged++;
                    } else {
                        $failed++;
                        $logger->warning('verified: keypair rotation purge failed to unlink encrypted document', [
                            'request_id' => (int) $row->id,
                            'user_id'    => (int) $row->user_id,
                        ]);
                    }
                }
            });

        $logger->info('verified: keypair rotation purge finished', [
            'purged' => $purged,
            'failed' => $failed,
        ]);
    }
}
