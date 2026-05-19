<?php

namespace Ramon\Verified\Documents;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;
use Ramon\Verified\Models\VerificationRequest;
use Throwable;

/**
 * Ciclo de vida dos arquivos de documentos no disco
 * `flarum-verified-documents`. Três modos são suportados pela setting
 * `ramon-verified.document_retention`:
 *
 * - `keep`              — não apaga automaticamente; admin retém indefinidamente.
 * - `delete_immediate`  — apaga assim que a solicitação é finalizada.
 * - `delete_after_days` — apaga N dias após a finalização (comando agendado).
 *
 * Único ponto autorizado a deletar arquivos de documentos fora do pipeline
 * GDPR. Toda mutação passa pelo disco — nada de FS nativa.
 */
class DocumentRetention
{
    public const MODE_KEEP              = 'keep';
    public const MODE_DELETE_IMMEDIATE  = 'delete_immediate';
    public const MODE_DELETE_AFTER_DAYS = 'delete_after_days';

    /**
     * Janela em que um arquivo recém-enviado mas ainda não submetido fica
     * imune ao `sweepOrphans`. Suficientemente maior que a latência típica
     * entre upload e submit, suficientemente menor para que órfãos reais
     * não acumulem.
     */
    public const UNREFERENCED_GRACE_SECONDS = 30 * 60;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Factory $filesystem,
        protected DocumentPathResolver $resolver,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * Aplica a política de retenção a uma solicitação recém-finalizada.
     * Modo `delete_immediate` apaga o arquivo e zera `document_path`; demais
     * modos são no-op (o purge agendado cuida de `delete_after_days`).
     */
    public function onRequestHandled(VerificationRequest $request): void
    {
        if ($this->mode() !== self::MODE_DELETE_IMMEDIATE) {
            return;
        }

        $this->purgeRequest($request);
    }

    public function purgeRequest(VerificationRequest $request): void
    {
        if (! $request->document_path) {
            return;
        }

        $this->safeDeleteFromDisk($request);

        $request->document_path = null;
        $request->save();
    }

    /**
     * Apaga apenas o arquivo associado à solicitação sem persistir o
     * model — usado em hooks `deleting` de Eloquent onde a linha já vai
     * embora.
     */
    public function purgeFileForRequest(VerificationRequest $request): void
    {
        if (! $request->document_path) {
            return;
        }

        $this->safeDeleteFromDisk($request);
    }

    /**
     * Apaga o diretório inteiro de um usuário (chamado no delete da conta —
     * o `cascadeOnDelete` da FK não dispara eventos Eloquent por linha).
     */
    public function purgeAllForUser(int $userId): int
    {
        $disk    = $this->disk();
        $userDir = $this->resolver->userDirectory($userId);

        if (! $disk->directoryExists($userDir)) {
            return 0;
        }

        $files = $disk->files($userDir);
        $count = count($files);

        try {
            $disk->deleteDirectory($userDir);
        } catch (Throwable $e) {
            $this->logger->warning('verified: failed to delete user document directory', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return 0;
        }

        return $count;
    }

    /**
     * Sweep de arquivos órfãos no diretório do usuário — arquivos que não
     * são referenciados por nenhuma linha não-rejeitada de `verification_requests`.
     * Chamado pelo controller de upload antes de gravar um novo arquivo
     * para impedir loop "upload-submit-delete" abusivo. Retorna a contagem
     * removida.
     */
    public function sweepOrphans(int $userId): int
    {
        $disk    = $this->disk();
        $userDir = $this->resolver->userDirectory($userId);

        if (! $disk->directoryExists($userDir)) {
            return 0;
        }

        $referenced = VerificationRequest::query()
            ->where('user_id', $userId)
            ->whereNotNull('document_path')
            ->where('status', '!=', VerificationRequest::STATUS_REJECTED)
            ->pluck('document_path')
            ->all();

        $referencedFilenames = [];
        foreach ($referenced as $path) {
            if (! is_string($path)) continue;
            $filename = basename($path);
            if (preg_match(DocumentPathResolver::FILENAME_PATTERN, $filename)) {
                $referencedFilenames[$filename] = true;
            }
        }

        $cutoff = time() - self::UNREFERENCED_GRACE_SECONDS;
        $purged = 0;

        foreach ($disk->files($userDir) as $relative) {
            $name = basename($relative);
            if (! preg_match(DocumentPathResolver::FILENAME_PATTERN, $name)) continue;
            if (isset($referencedFilenames[$name])) continue;

            try {
                $mtime = $disk->lastModified($relative);
            } catch (Throwable $e) {
                continue;
            }
            if ($mtime > $cutoff) continue;

            if ($this->safeDelete($relative)) {
                $purged++;
            }
        }

        return $purged;
    }

    /**
     * Purge baseado em tempo. Remove arquivos de toda solicitação finalizada
     * cujo `handled_at` ultrapassou a janela de retenção. No-op fora do
     * modo `delete_after_days`. Retorna a contagem de solicitações processadas.
     */
    public function purgeExpired(): int
    {
        if ($this->mode() !== self::MODE_DELETE_AFTER_DAYS) {
            return 0;
        }

        $days = $this->retentionDays();
        if ($days <= 0) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($days);
        $purged = 0;

        VerificationRequest::query()
            ->whereIn('status', [VerificationRequest::STATUS_APPROVED, VerificationRequest::STATUS_REJECTED])
            ->whereNotNull('document_path')
            ->whereNotNull('handled_at')
            ->where('handled_at', '<', $cutoff)
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$purged) {
                foreach ($rows as $row) {
                    try {
                        $this->purgeRequest($row);
                        $purged++;
                    } catch (Throwable $e) {
                        $this->logger->warning('verified: purgeExpired failed for request', [
                            'request_id' => (int) $row->id,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $purged;
    }

    public function mode(): string
    {
        $raw = (string) $this->settings->get('ramon-verified.document_retention', self::MODE_KEEP);

        return in_array($raw, [self::MODE_KEEP, self::MODE_DELETE_IMMEDIATE, self::MODE_DELETE_AFTER_DAYS], true)
            ? $raw
            : self::MODE_KEEP;
    }

    public function retentionDays(): int
    {
        $raw = (int) $this->settings->get('ramon-verified.document_retention_days', 30);

        return max(1, min($raw, 3650));
    }

    public function resolver(): DocumentPathResolver
    {
        return $this->resolver;
    }

    private function disk(): Filesystem
    {
        return $this->filesystem->disk(DocumentPathResolver::DISK);
    }

    private function safeDeleteFromDisk(VerificationRequest $request): void
    {
        $relative = $this->resolver->resolveRelative(
            (string) $request->document_path,
            (int) $request->user_id
        );
        if ($relative === null) {
            return;
        }

        $this->safeDelete($relative, (int) $request->id);
    }

    /**
     * Falhas de I/O são logadas e toleradas — retenção é best-effort.
     */
    private function safeDelete(string $relative, ?int $requestId = null): bool
    {
        $disk = $this->disk();

        try {
            return $disk->delete($relative);
        } catch (Throwable $e) {
            $this->logger->warning('verified: failed to delete document', [
                'path'       => $relative,
                'request_id' => $requestId,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }
}
