<?php

namespace Ramon\Verified\Documents;

/**
 * Resolve tokens armazenados em `document_path` (forma
 * `/assets/verified/{userId}/{filename}`) para paths disco-relativos no
 * `flarum-verified-documents`. Endurecido contra traversal: allowlist de
 * filename, rejeição de null byte / stream wrapper / `..`, casamento estrito
 * do userId.
 *
 * Devolve sempre **path disco-relativo** (ex.: `"42/abc.pdf"`), nunca path
 * absoluto. Callers operam pelo disco; o driver `local` do Flysystem cuida
 * do prefix-confinement.
 */
class DocumentPathResolver
{
    public const FILENAME_PATTERN = '/\A[a-f0-9]{32}\.(png|jpg|jpeg|webp|pdf)\z/i';

    public const PUBLIC_PREFIX = 'assets/verified/';

    /**
     * Disco registrado em `extend.php`. Root é `$paths->storage/verified-documents`.
     */
    public const DISK = 'flarum-verified-documents';

    public function userDirectory(int $userId): string
    {
        return (string) $userId;
    }

    public function resolveRelative(string $token, int $expectedUserId): ?string
    {
        $token = ltrim($token, '/\\');

        if ($token === '' || str_contains($token, "\0") || str_contains($token, '://')) {
            return null;
        }

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

        if ((int) $userIdPart !== $expectedUserId || (string) (int) $userIdPart !== $userIdPart) {
            return null;
        }

        if (! preg_match(self::FILENAME_PATTERN, $filename)) {
            return null;
        }

        return $userIdPart.'/'.$filename;
    }

    public function isOwnedDocumentToken(string $token, int $userId): bool
    {
        return $this->resolveRelative($token, $userId) !== null;
    }
}
