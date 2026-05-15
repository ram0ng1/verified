<?php

namespace Ramon\Verified\Documents;

use Carbon\Carbon;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Log\LoggerInterface;
use Ramon\Verified\Models\VerificationRequest;
use Throwable;

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

    /**
     * How long a freshly-uploaded but not-yet-submitted document is allowed
     * to live in the user's directory before `sweepOrphans` may delete it.
     * Bigger than the realistic time between calling the upload endpoint
     * and submitting the verification request, but small enough that
     * truly-orphaned files don't accumulate.
     */
    public const UNREFERENCED_GRACE_SECONDS = 30 * 60;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Paths $paths,
        protected DocumentPathResolver $resolver,
        protected LoggerInterface $logger
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

        $absolute = $this->resolver->resolveAbsolute((string) $request->document_path, (int) $request->user_id);
        if ($absolute !== null && is_file($absolute)) {
            $this->safeUnlink($absolute, (int) $request->id);
        }

        // Clear the path even when the file was already missing — the row
        // should not keep pointing at a deleted asset.
        $request->document_path = null;
        $request->save();
    }

    /**
     * Unlink the document file referenced by a request without saving the
     * model. Used by `deleting` model hooks where the row is about to be
     * removed entirely (so there is no point persisting a `document_path =
     * null` on a row that won't exist a moment later).
     */
    public function purgeFileForRequest(VerificationRequest $request): void
    {
        if (! $request->document_path) {
            return;
        }

        $absolute = $this->resolver->resolveAbsolute((string) $request->document_path, (int) $request->user_id);
        if ($absolute !== null && is_file($absolute)) {
            $this->safeUnlink($absolute, (int) $request->id);
        }
    }

    /**
     * Hard-purge every document file owned by a user. Used by the
     * `User deleted` listener to avoid orphaned files when an admin (or
     * the GDPR fallback) hard-deletes a user — `cascadeOnDelete` on the
     * `verification_requests` FK silently removes the rows WITHOUT
     * dispatching model events, so the per-row listener never fires
     * (CLAUDE.md §26 + audit finding F7).
     *
     * Returns the number of files unlinked.
     */
    public function purgeAllForUser(int $userId): int
    {
        $userDir = $this->resolver->userDirectory($userId);
        if (! is_dir($userDir)) {
            return 0;
        }

        $purged  = 0;
        $entries = @scandir($userDir) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = $userDir.DIRECTORY_SEPARATOR.$entry;
            if (is_file($full) && $this->safeUnlink($full)) {
                $purged++;
            }
        }

        @rmdir($userDir);

        return $purged;
    }

    /**
     * Wrap `unlink` so I/O failures get logged instead of being swallowed
     * by the previous `@unlink` operator. We still tolerate the failure —
     * retention sweep is best-effort — but ops needs a trail when files
     * pile up because of permission or filesystem issues.
     */
    protected function safeUnlink(string $absolute, ?int $requestId = null): bool
    {
        try {
            if (@unlink($absolute)) {
                return true;
            }
        } catch (Throwable $e) {
            $this->logger->warning('verified: unlink threw exception', [
                'path'       => $absolute,
                'request_id' => $requestId,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }

        // The file may have been removed by something else between is_file()
        // and unlink() — that's fine; only log when it is still there.
        if (file_exists($absolute)) {
            $this->logger->warning('verified: failed to unlink document', [
                'path'       => $absolute,
                'request_id' => $requestId,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Sweep stray document files in a user's verified-documents directory
     * that are NOT referenced by any non-rejected request row. Returns the
     * number of files unlinked.
     *
     * Called from the upload controller before storing a new file: this
     * keeps a malicious user from racking up disk usage by repeatedly
     * uploading + deleting pending requests, and also cleans up any
     * "uploaded but never submitted" leftovers.
     */
    public function sweepOrphans(int $userId): int
    {
        $base = $this->resolver->baseDirectory();
        if ($base === null) {
            return 0;
        }

        $userDir = $base.DIRECTORY_SEPARATOR.$userId;
        if (! is_dir($userDir)) {
            return 0;
        }

        // Files referenced by any request row that is NOT rejected — those
        // are still "alive" (pending review or already approved with audit).
        // Rejected rows are audit history; the file should already be gone
        // anyway thanks to retention modes.
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
            // Defensive — only trust filenames that match our generator's
            // shape so a malicious row can't shield arbitrary files.
            if (preg_match(DocumentPathResolver::FILENAME_PATTERN, $filename)) {
                $referencedFilenames[$filename] = true;
            }
        }

        // Only sweep files that have aged past the "still in-flight" window.
        // The upload endpoint returns a token immediately; the user then has
        // up to UNREFERENCED_GRACE_SECONDS to attach it to a request row. A
        // shorter window would let a second concurrent upload race-delete the
        // first user's just-uploaded but not-yet-submitted file (audit F10).
        $cutoff = time() - self::UNREFERENCED_GRACE_SECONDS;

        $purged = 0;
        $entries = @scandir($userDir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            // Skip anything whose name doesn't match our generator — leave
            // unknown files untouched rather than guessing.
            if (! preg_match(DocumentPathResolver::FILENAME_PATTERN, $entry)) continue;
            if (isset($referencedFilenames[$entry])) continue;

            $full = $userDir.DIRECTORY_SEPARATOR.$entry;
            if (! is_file($full)) continue;

            // Spare freshly-uploaded files inside the grace window so two
            // concurrent uploads can't sweep each other.
            $mtime = @filemtime($full);
            if ($mtime !== false && $mtime > $cutoff) continue;

            if ($this->safeUnlink($full)) {
                $purged++;
            }
        }

        return $purged;
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
                    // One bad row (filesystem error, locked file, lost
                    // database connection mid-save) must not abort the
                    // entire scheduled purge — log and keep going.
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

        // Clamp to a sane window — zero would purge instantly (use
        // delete_immediate for that), and an unbounded value defeats the
        // point of having a cap.
        return max(1, min($raw, 3650));
    }

    public function resolver(): DocumentPathResolver
    {
        return $this->resolver;
    }
}
