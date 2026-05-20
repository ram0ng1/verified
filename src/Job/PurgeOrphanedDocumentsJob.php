<?php

namespace Ramon\Verified\Job;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Ramon\Verified\Crypto\DocumentCipher;
use Ramon\Verified\Documents\DocumentPathResolver;
use Ramon\Verified\Models\VerificationRequest;
use Throwable;

/**
 * Apaga arquivos cifrados pela chave antiga após uma rotação de par.
 * Despachado depois que a chave pública já foi esquecida, então uploads
 * concorrentes durante a janela do job caem no path plaintext. A nova
 * chave pública é gerada ANTES do dispatch para que novos uploads sejam
 * cifrados imediatamente.
 *
 * Em queue driver `sync` (default do Flarum) roda inline; sob `redis` /
 * `database` roda em worker, sem amarrar o request de admin numa
 * varredura full-corpus que pode estourar `request_terminate_timeout`.
 */
class PurgeOrphanedDocumentsJob implements ShouldQueue
{
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function handle(
        Factory $filesystem,
        DocumentPathResolver $resolver,
        LoggerInterface $logger
    ): void {
        $disk = $filesystem->disk(DocumentPathResolver::DISK);
        $purged = 0;
        $failed = 0;

        VerificationRequest::query()
            ->whereNotNull('document_path')
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($disk, $resolver, $logger, &$purged, &$failed) {
                foreach ($rows as $row) {
                    $relative = $resolver->resolveRelative(
                        (string) $row->document_path,
                        (int) $row->user_id
                    );
                    if ($relative === null || ! $disk->exists($relative)) {
                        continue;
                    }

                    try {
                        $blob = $disk->get($relative);
                    } catch (Throwable $e) {
                        $failed++;
                        continue;
                    }
                    if (! is_string($blob) || ! DocumentCipher::isEncryptedBlob($blob)) {
                        continue;
                    }

                    try {
                        $deleted = $disk->delete($relative);
                    } catch (Throwable $e) {
                        $deleted = false;
                    }

                    if ($deleted || ! $disk->exists($relative)) {
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
