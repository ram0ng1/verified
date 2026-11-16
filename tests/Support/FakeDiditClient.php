<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Tests\Support;

use Ramon\Verified\Didit\DiditClient;
use Ramon\Verified\Didit\DiditException;

/**
 * Didit falsa para a matriz do CI: respostas fixas por "MÉTODO caminho" e o
 * registro de cada chamada. O teste com a API real fica em tests/live.
 */
class FakeDiditClient extends DiditClient
{
    public const SESSION_ID = '11111111-2222-4333-8444-555555555555';

    /** @var array<string, array<mixed>|int> resposta ou código de erro HTTP */
    public static array $responses = [];

    /** @var list<array{method: string, path: string, body: array<string, mixed>|null}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
        self::$responses = [
            'POST /v3/session/' => ['session_id' => self::SESSION_ID, 'url' => 'https://verify.didit.me/session/fake', 'status' => 'Not Started'],
            'GET /v3/session/'.self::SESSION_ID.'/decision/' => ['session_id' => self::SESSION_ID, 'status' => 'Approved'],
            'GET /v3/workflows/' => [
                ['uuid' => 'b2093113-38ea-43ae-9d5c-113f32fe0c95', 'workflow_label' => 'Basic KYC', 'workflow_type' => 'kyc', 'is_default' => true],
            ],
        ];
    }

    protected function send(string $method, string $path, ?array $body = null): array
    {
        self::$calls[] = ['method' => $method, 'path' => $path, 'body' => $body];
        $response = self::$responses[$method.' '.$path] ?? 404;

        if (is_int($response)) {
            throw new DiditException("Fake Didit {$method} {$path} failed with HTTP {$response}", $response);
        }

        return $response;
    }
}
