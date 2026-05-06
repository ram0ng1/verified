<?php

namespace Ramon\Verified\Documents;

use Carbon\Carbon;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Ramon\Verified\Models\VerificationRequest;

/**
 * Centralised lifecycle for verification document files on disk.
 *
 * Three retention modes are supported (configured via the
 * `ramon-verified.document_retention` setting):
 *
 *   - keep              — never auto-delete; admin keeps files indefinitely
 *   - delete_immediate  — wipe the file as soon as the request is handled
 *   - delete_after_days — wipe N days after the request was handled
 *
 * The "after days" mode is enforced by the daily PurgeDocuments console
 * command. The other two modes only ever fire from the request workflow
 * (approve / reject / revoke / direct verify).
 *
 * This service is the ONLY place that should unlink document files outside
 * of the GDPR pipeline (which has its own erasure semantics).
 */
class DocumentRetention
{
    public const MODE_KEEP              = 'keep';
    public const MODE_DELETE_IMMEDIATE  = 'delete_immediate';
    public const MODE_DELETE_AFTER_DAYS = 'delete_after_days';

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Paths $paths
    ) {
    }

    /**
     * Apply the configured retention mode to a request that was just handled
     * (approved / rejected / revoked). For `delete_immediate` this unlinks
     * the file and clears `document_path`; for the other modes it is a no-op
     * — `delete_after_days` is handled by the scheduled purge.
     *
     * The model is saved when this method mutates it; callers don't need to.
     */
    public function onRequestHandled(VerificationRequest $request): void
    {
        if ($this->mode() !== self::MODE_DELETE_IMMEDIATE) {
            return;
        }

        $this->purgeRequest($request);
    }

    /**
     * Unlink the document file backing a request and null out the path on
     * the model. Safe to call when the path is already null or the file is
     * already gone — does nothing in those cases.
     */
    public function purgeRequest(VerificationRequest $request): void
    {
        if (! $request->document_path) {
            return;
        }

        $absolute = $this->resolveAbsolutePath((string) $request->document_path, (int) $request->user_id);
        if ($absolute !== null && is_file($absolute)) {
            @unlink($absolute);
        }

        // Clear the path even when the file was already missing — the row
        // should not keep pointing at a deleted asset.
        $request->document_path = null;
        $request->save();
    }

    /**
     * Run the time-based purge. Removes documents from every handled
     * request older than the configured retention window. Returns the
     * number of requests purged (used for console output).
     *
     * No-op unless the configured mode is `delete_after_days`.
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
                    $this->purgeRequest($row);
                    $purged++;
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

        // Clamp to a sane window — zero would purge instantly (use
        // delete_immediate for that), and an unbounded value defeats the
        // point of having a cap.
        return max(1, min($raw, 3650));
    }

    /**
     * Resolve a stored `document_path` (e.g. "/assets/verified/123/abc.pdf")
     * into a real on-disk path inside `storage/verified-documents/`. Mirrors
     * the validation done by the download controller — refuses traversal
     * attempts and paths that don't sit under the user's own directory.
     */
    protected function resolveAbsolutePath(string $token, int $expectedUserId): ?string
    {
        $token = ltrim($token, '/');

        if (str_contains($token, '..') || str_contains($token, "\0")) {
            return null;
        }

        $prefix = 'assets/verified/';
        if (! str_starts_with($token, $prefix)) {
            return null;
        }

        $rest = substr($token, strlen($prefix));
        $parts = explode('/', $rest);
        if (count($parts) !== 2) {
            return null;
        }

        [$userIdPart, $filename] = $parts;
        if ((int) $userIdPart !== $expectedUserId) {
            return null;
        }

        if (! preg_match('/^[a-f0-9]{32}\.(png|jpg|jpeg|webp|pdf)$/i', $filename)) {
            return null;
        }

        $base = realpath(rtrim($this->paths->storage, '/\\').DIRECTORY_SEPARATOR.'verified-documents');
        if ($base === false) {
            return null;
        }

        $candidate = $base.DIRECTORY_SEPARATOR.$userIdPart.DIRECTORY_SEPARATOR.$filename;
        $absolute = realpath($candidate);

        if ($absolute === false || ! str_starts_with($absolute, $base.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $absolute;
    }
}
