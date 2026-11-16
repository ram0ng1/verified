<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Ramon\Verified\Tests\unit;

use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Ramon\Verified\Didit\WebhookSignature;

/**
 * Assinatura dos webhooks da Didit: bytes crus (X-Signature) ou JSON canônico
 * (X-Signature-V2), janela de 5 minutos e rotação de segredo.
 */
final class DiditWebhookSignatureTest extends TestCase
{
    private const SECRET = 'segredo-de-teste';
    private const NOW = 1774970000;

    // Chaves fora de ordem, Unicode e barras escapados, objeto vazio e float inteiro.
    private const RAW = '{"status":"Approved","session_id":"11111111-2222-4333-8444-555555555555","webhook_type":"status.updated","decision":{"name":"Jos\\u00e9","url":"https:\\/\\/media.didit.me\\/a","risk":{},"score":1.0,"items":[2,1]}}';

    // O que a Didit assina na V2: chaves ordenadas, compacto, sem escapes, 1.0 como 1.
    private const CANONICAL = '{"decision":{"items":[2,1],"name":"José","risk":{},"score":1,"url":"https://media.didit.me/a"},"session_id":"11111111-2222-4333-8444-555555555555","status":"Approved","webhook_type":"status.updated"}';

    private function request(array $headers): ServerRequest
    {
        return new ServerRequest([], [], '/api/verified/didit/webhook', 'POST', 'php://memory', $headers + ['X-Timestamp' => (string) self::NOW]);
    }

    private function sign(string $payload, string $secret = self::SECRET): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    public function test_canonical_reproduz_o_formato_da_didit(): void
    {
        self::assertSame(self::CANONICAL, WebhookSignature::canonical(self::RAW));
    }

    public function test_aceita_x_signature_dos_bytes_crus(): void
    {
        $request = $this->request(['X-Signature' => $this->sign(self::RAW)]);

        self::assertTrue(WebhookSignature::verify($request, self::RAW, self::SECRET, self::NOW));
    }

    public function test_aceita_x_signature_v2_do_json_canonico(): void
    {
        $request = $this->request(['X-Signature-V2' => $this->sign(self::CANONICAL)]);

        self::assertTrue(WebhookSignature::verify($request, self::RAW, self::SECRET, self::NOW));
    }

    public function test_aceita_a_assinatura_do_segredo_anterior_na_rotacao(): void
    {
        $request = $this->request([
            'X-Signature-V2' => $this->sign(self::CANONICAL, 'segredo-novo-ainda-nao-implantado'),
            'X-Signature-V2-Previous' => $this->sign(self::CANONICAL),
        ]);

        self::assertTrue(WebhookSignature::verify($request, self::RAW, self::SECRET, self::NOW));
    }

    public function test_recusa_corpo_alterado(): void
    {
        $request = $this->request(['X-Signature' => $this->sign(self::RAW)]);
        $tampered = str_replace('Approved', 'Declined', self::RAW);

        self::assertFalse(WebhookSignature::verify($request, $tampered, self::SECRET, self::NOW));
    }

    public function test_recusa_segredo_errado(): void
    {
        $request = $this->request(['X-Signature' => $this->sign(self::RAW, 'outro')]);

        self::assertFalse(WebhookSignature::verify($request, self::RAW, self::SECRET, self::NOW));
    }

    public function test_recusa_timestamp_fora_da_janela(): void
    {
        $request = $this->request(['X-Signature' => $this->sign(self::RAW)]);

        self::assertFalse(WebhookSignature::verify($request, self::RAW, self::SECRET, self::NOW + 301));
        self::assertTrue(WebhookSignature::verify($request, self::RAW, self::SECRET, self::NOW + 300));
    }

    /** A Simple não cobre o corpo: sozinha, não autentica nada. */
    public function test_recusa_so_x_signature_simple(): void
    {
        $simple = $this->sign(self::NOW.':11111111-2222-4333-8444-555555555555:Approved:status.updated');
        $request = $this->request(['X-Signature-Simple' => $simple]);

        self::assertFalse(WebhookSignature::verify($request, self::RAW, self::SECRET, self::NOW));
    }

    public function test_recusa_sem_segredo_configurado(): void
    {
        $request = $this->request(['X-Signature' => hash_hmac('sha256', self::RAW, '')]);

        self::assertFalse(WebhookSignature::verify($request, self::RAW, '', self::NOW));
    }
}
