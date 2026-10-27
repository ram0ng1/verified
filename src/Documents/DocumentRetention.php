<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Documents;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;
use Ramon\Verified\Models\VerificationRequest;
use Throwable;

/**
 * Retenção dos documentos (`ramon-verified.document_retention`): `keep`,
 * `delete_immediate` (ao finalizar a solicitação) ou `delete_after_days`
 * (comando agendado). Fora do pipeline GDPR, só esta classe apaga documentos.
 */
class DocumentRetention
{
    public const MODE_KEEP              = 'keep';
    public const MODE_DELETE_IMMEDIATE  = 'delete_immediate';
    public const MODE_DELETE_AFTER_DAYS = 'delete_after_days';

    /**
     * Período em que um upload ainda não submetido fica imune ao `sweepOrphans`.
     */
    public const UNREFERENCED_GRACE_SECONDS = 30 * 60;

    /**
     * Sem este teto, um membro podia enviar 5 arquivos de 8 MB por minuto sem
     * abrir solicitação e encher o disco.
     */
    public const MAX_UNATTACHED_PER_USER = 3;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Factory $filesystem,
        protected DocumentPathResolver $resolver,
        protected LoggerInterface $logger
    ) {
    }

    /**
     * Só age em `delete_immediate`; `delete_after_days` fica com o purge agendado.
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
     * Não persiste o model: usado em hooks `deleting`, quando a linha já vai embora.
     */
    public function purgeFileForRequest(VerificationRequest $request): void
    {
        if (! $request->document_path) {
            return;
        }

        $this->safeDeleteFromDisk($request);
    }

    /**
     * O `cascadeOnDelete` da FK não dispara eventos Eloquent, por isso o diretório
     * é apagado explicitamente.
     */
    public function purgeAllForUser(int $userId): int
    {
        $disk    = $this->disk();
        $userDir = $this->resolver->userDirectory($userId);

        if (! $disk->exists($userDir)) {
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
     * Toda linha com `document_path` conta como referência, qualquer que seja o
     * status: quem decide o destino do documento de uma solicitação rejeitada é a
     * política de retenção, e no modo `keep` ele tem de ficar.
     */
    public function sweepOrphans(int $userId): int
    {
        $cutoff = time() - self::UNREFERENCED_GRACE_SECONDS;
        $purged = 0;

        foreach ($this->unattachedFiles($userId) as $relative => $mtime) {
            if ($mtime > $cutoff) continue;

            if ($this->safeDelete($relative)) {
                $purged++;
            }
        }

        return $purged;
    }

    /**
     * O upload é recusado ao chegar a `MAX_UNATTACHED_PER_USER`.
     */
    public function countUnattached(int $userId): int
    {
        return count($this->unattachedFiles($userId));
    }

    /**
     * Sem isto, os arquivos de quem envia e nunca abre solicitação ficariam para
     * sempre: o sweep por usuário só roda no próximo upload dele.
     */
    public function sweepAllOrphans(): int
    {
        $disk   = $this->disk();
        $purged = 0;

        foreach ($disk->directories('') as $dir) {
            if (! preg_match('/\A[0-9]+\z/', basename($dir))) continue;

            $purged += $this->sweepOrphans((int) basename($dir));
        }

        return $purged;
    }

    /**
     * @return array<string, int> caminho relativo => mtime, dos arquivos do
     *                            usuário que nenhuma solicitação referencia
     */
    private function unattachedFiles(int $userId): array
    {
        $disk    = $this->disk();
        $userDir = $this->resolver->userDirectory($userId);

        if (! $disk->exists($userDir)) {
            return [];
        }

        $referenced = VerificationRequest::query()
            ->where('user_id', $userId)
            ->whereNotNull('document_path')
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

        $files = [];

        foreach ($disk->files($userDir) as $relative) {
            $name = basename($relative);
            if (! preg_match(DocumentPathResolver::FILENAME_PATTERN, $name)) continue;
            if (isset($referencedFilenames[$name])) continue;

            try {
                $files[$relative] = (int) $disk->lastModified($relative);
            } catch (Throwable $e) {
                continue;
            }
        }

        return $files;
    }

    /**
     * Retorna o número de solicitações processadas.
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

        /*
         * `chunkById`, não `chunk`: `purgeRequest()` zera a própria coluna do
         * `whereNotNull`, e a paginação por OFFSET pularia linhas não tratadas.
         */
        VerificationRequest::query()
            ->whereIn('status', [VerificationRequest::STATUS_APPROVED, VerificationRequest::STATUS_REJECTED])
            ->whereNotNull('document_path')
            ->whereNotNull('handled_at')
            ->where('handled_at', '<', $cutoff)
            ->chunkById(200, function ($rows) use (&$purged) {
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
     * Retenção é best-effort: falhas de I/O são logadas e toleradas.
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
