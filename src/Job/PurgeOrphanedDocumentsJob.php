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
 * A nova chave pública já existe quando o job roda, então os uploads da
 * janela entre a rotação e o worker também levam o marcador `VENC1` —
 * cifrados com a chave NOVA. Por isso o job só apaga arquivos gravados
 * antes de `$rotatedAt` (o blob não guarda a impressão da chave).
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
         * `chunkById`: o callback zera `document_path`, a coluna do
         * `whereNotNull('document_path')`. `chunk` (OFFSET) pularia linhas
         * conforme o result set encolhe; `chunkById` pagina por `id`.
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
