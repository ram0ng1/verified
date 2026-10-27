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

/**
 * Converte o `document_path` (`/assets/verified/{userId}/{filename}`) em path
 * relativo ao disco `flarum-verified-documents`, nunca absoluto. Contra
 * traversal: allowlist de filename, sem null byte, stream wrapper ou `..`, e o
 * userId tem de casar exatamente.
 */
class DocumentPathResolver
{
    public const FILENAME_PATTERN = '/\A[a-f0-9]{32}\.(png|jpg|jpeg|webp|pdf)\z/i';

    public const PUBLIC_PREFIX = 'assets/verified/';

    /**
     * Raiz em `$paths->storage/verified-documents`.
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
