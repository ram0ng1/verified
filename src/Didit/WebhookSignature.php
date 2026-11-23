<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Didit;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Assinatura dos webhooks da Didit. Aceita `X-Signature` (HMAC dos bytes crus)
 * ou `X-Signature-V2` (HMAC do JSON canônico), e as variantes `-Previous`
 * das 24 h seguintes a uma troca de segredo. A `X-Signature-Simple` não é
 * aceita: ela não cobre o corpo.
 */
class WebhookSignature
{
    public const TOLERANCE_SECONDS = 300;

    public static function verify(ServerRequestInterface $request, string $rawBody, string $secret, ?int $now = null): bool
    {
        if ($secret === '') {
            return false;
        }

        $timestamp = $request->getHeaderLine('X-Timestamp');
        if (! ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $raw = hash_hmac('sha256', $rawBody, $secret);
        $canonical = self::canonical($rawBody);
        $v2 = $canonical === null ? null : hash_hmac('sha256', $canonical, $secret);

        foreach (['X-Signature', 'X-Signature-Previous'] as $header) {
            if (self::matches($request->getHeaderLine($header), $raw)) return true;
        }

        if ($v2 !== null) {
            foreach (['X-Signature-V2', 'X-Signature-V2-Previous'] as $header) {
                if (self::matches($request->getHeaderLine($header), $v2)) return true;
            }
        }

        return false;
    }

    /**
     * JSON canônico da Didit: chaves ordenadas, compacto, Unicode e barras sem
     * escape, objetos vazios mantidos como objetos e floats inteiros como ints.
     */
    public static function canonical(string $rawBody): ?string
    {
        $decoded = json_decode($rawBody, false);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $encoded = json_encode(self::normalize($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $props = get_object_vars($value);
            ksort($props, SORT_STRING);
            $sorted = new \stdClass();
            foreach ($props as $key => $item) {
                $sorted->{$key} = self::normalize($item);
            }

            return $sorted;
        }

        if (is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (int) $value;
        }

        return $value;
    }

    private static function matches(string $header, string $expected): bool
    {
        return $header !== '' && hash_equals($expected, strtolower(trim($header)));
    }
}
