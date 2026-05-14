<?php

namespace Ramon\Verified\Documents;

use Flarum\Foundation\Paths;

/**
 * Single seam for resolving stored `document_path` tokens (e.g.
 * `/assets/verified/123/abc.pdf`) into real on-disk paths under
 * `storage/verified-documents/`. Mirrors the §13 traversal hardening guide
 * from CLAUDE.md: filename allowlist, null-byte rejection, stream-wrapper
 * rejection, `realpath` + prefix-with-separator confinement.
 *
 * Every caller that needs to map a stored path to an absolute filesystem
 * path MUST go through this class. Three earlier copies of this logic
 * (DownloadDocumentController, GenerateKeypairController, DocumentRetention)
 * previously drifted apart — this class is the consolidation.
 */
class DocumentPathResolver
{
    // `\A...\z` (not `^...$`) so a trailing newline inside the input can't
    // sneak past the anchor — per CLAUDE.md §13.6. The length anchoring on
    // 32 hex chars + extension already makes this redundant, but the
    // tighter anchors document intent and remove the question.
    public const FILENAME_PATTERN = '/\A[a-f0-9]{32}\.(png|jpg|jpeg|webp|pdf)\z/i';

    public const PUBLIC_PREFIX = 'assets/verified/';

    public function __construct(
        protected Paths $paths
    ) {
    }

    /**
     * Return the canonicalised base directory holding every user's documents.
     * `null` when the directory has not been created yet (no uploads yet) —
     * callers treat that as "nothing to resolve".
     */
    public function baseDirectory(): ?string
    {
        $base = realpath(rtrim($this->paths->storage, '/\\').DIRECTORY_SEPARATOR.'verified-documents');
        return $base === false ? null : $base;
    }

    /**
     * Get the per-user documents directory (not realpath'd — may not exist
     * yet on first upload). Always confined to the verified-documents base
     * because `$userId` is forced to an int.
     */
    public function userDirectory(int $userId): string
    {
        return rtrim($this->paths->storage, '/\\')
            .DIRECTORY_SEPARATOR.'verified-documents'
            .DIRECTORY_SEPARATOR.$userId;
    }

    /**
     * Resolve a stored token into an absolute filesystem path, confining the
     * resolution to `storage/verified-documents/{$expectedUserId}/`. Returns
     * `null` for any invalid or traversal-attempting input — callers MUST
     * treat that as 404, never differentiate from "not found".
     */
    public function resolveAbsolute(string $token, int $expectedUserId): ?string
    {
        $relative = $this->resolveRelative($token, $expectedUserId);
        if ($relative === null) return null;

        $base = $this->baseDirectory();
        if ($base === null) return null;

        $candidate = $base.DIRECTORY_SEPARATOR.$relative;
        $absolute  = realpath($candidate);

        if ($absolute === false || ! str_starts_with($absolute.DIRECTORY_SEPARATOR, $base.DIRECTORY_SEPARATOR.$expectedUserId.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $absolute;
    }

    /**
     * Resolve a stored token into the storage-relative path "{userId}/{filename}",
     * without touching the filesystem. Useful for callers that build their own
     * stream out of the relative path (e.g. the download controller).
     */
    public function resolveRelative(string $token, int $expectedUserId): ?string
    {
        $token = ltrim($token, '/\\');

        // Stream wrappers + null bytes blocked before any further processing
        // (per CLAUDE.md §13.2 / §13.4).
        if ($token === '' || str_contains($token, "\0") || str_contains($token, '://')) {
            return null;
        }

        // Reject every decoded variant of `..` — the input arrives URL-decoded
        // by PSR-7, but a defensive sanity check costs nothing.
        if (str_contains($token, '..')) {
            return null;
        }

        if (! str_starts_with($token, self::PUBLIC_PREFIX)) {
            return null;
        }

        $rest  = substr($token, strlen(self::PUBLIC_PREFIX));
        $parts = explode('/', $rest);

        if (count($parts) !== 2) {
            return null;
        }

        [$userIdPart, $filename] = $parts;

        // Strict integer match — `null == 0` is true in PHP, so this MUST be
        // a `===` after `(int)` on both sides (see CLAUDE.md §3 footguns).
        if ((int) $userIdPart !== $expectedUserId || (string) (int) $userIdPart !== $userIdPart) {
            return null;
        }

        if (! preg_match(self::FILENAME_PATTERN, $filename)) {
            return null;
        }

        return $userIdPart.'/'.$filename;
    }

    /**
     * Cheap shape check used by the resource layer when it can't (or
     * shouldn't) touch the filesystem: validates that a `documentPath`
     * submitted on a Create payload claims to belong to the actor.
     */
    public function isOwnedDocumentToken(string $token, int $userId): bool
    {
        return $this->resolveRelative($token, $userId) !== null;
    }
}
