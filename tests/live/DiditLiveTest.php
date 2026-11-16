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

namespace Ramon\Verified\Tests\live;

use PHPUnit\Framework\TestCase;
use Ramon\Verified\Didit\DiditClient;
use Ramon\Verified\Didit\DiditConfig;
use Ramon\Verified\Didit\DiditException;
use Ramon\Verified\Tests\Support\ArraySettings;

/**
 * Contra a API real da Didit. Roda só com DIDIT_API_KEY no ambiente (segredo do
 * repositório no CI); sem ela, os testes são pulados. A sessão criada é apagada
 * no fim — sessões não concluídas não são cobradas.
 */
final class DiditLiveTest extends TestCase
{
    private static ?string $sessionId = null;

    private function client(?string $key = null, ?string $workflow = null): DiditClient
    {
        $key ??= (string) getenv('DIDIT_API_KEY');
        if ($key === '') {
            self::markTestSkipped('DIDIT_API_KEY não definida.');
        }

        return new DiditClient(new DiditConfig(new ArraySettings([
            DiditConfig::API_KEY => $key,
            DiditConfig::WORKFLOW_ID => $workflow ?? (string) getenv('DIDIT_WORKFLOW_ID'),
        ])));
    }

    public function test_lista_os_workflows(): void
    {
        $workflows = $this->client()->listWorkflows();

        self::assertNotEmpty($workflows);
        foreach ($workflows as $workflow) {
            self::assertTrue(DiditClient::isSessionId($workflow['id']));
            self::assertNotSame('', $workflow['label']);
        }
    }

    public function test_chave_invalida_da_401(): void
    {
        $this->client(); // pula sem chave, para não depender da rede à toa

        try {
            $this->client('sk_live_chave-invalida')->listWorkflows();
            self::fail('A Didit aceitou uma chave inválida.');
        } catch (DiditException $e) {
            self::assertContains($e->getCode(), [401, 403]);
        }
    }

    public function test_cria_a_sessao(): void
    {
        try {
            $session = $this->client()->createSession(
                'flarum-live-test',
                'https://example.com/verified/didit/return',
                ['source' => 'ramon/verified live test'],
                'pt'
            );
        } catch (DiditException $e) {
            // Saldo é da conta, não do código: a requisição foi aceita como válida.
            if (str_contains($e->getMessage(), 'enough credits')) {
                self::markTestSkipped('Conta da Didit sem créditos para criar sessões (use uma chave de sandbox).');
            }

            throw $e;
        }

        self::assertTrue(DiditClient::isSessionId($session['session_id']));
        self::assertStringStartsWith('https://', $session['url']);
        self::assertSame('Not Started', $session['status']);

        self::$sessionId = $session['session_id'];
    }

    /** Mesmo vendor_data: a Didit devolve a sessão em aberto, não cria outra. */
    public function test_criar_de_novo_devolve_a_mesma_sessao(): void
    {
        if (self::$sessionId === null) self::markTestSkipped('Sem sessão criada.');

        $again = $this->client()->createSession('flarum-live-test', 'https://example.com/verified/didit/return');

        self::assertSame(self::$sessionId, $again['session_id']);
    }

    public function test_busca_a_decisao(): void
    {
        if (self::$sessionId === null) self::markTestSkipped('Sem sessão criada.');

        $decision = $this->client()->getDecision(self::$sessionId);

        self::assertSame(self::$sessionId, $decision['session_id']);
        self::assertSame('Not Started', $decision['status']);
    }

    public function test_apaga_a_sessao(): void
    {
        if (self::$sessionId === null) self::markTestSkipped('Sem sessão criada.');

        $this->client()->deleteSession(self::$sessionId);

        try {
            $this->client()->getDecision(self::$sessionId);
            self::fail('A sessão apagada ainda responde.');
        } catch (DiditException $e) {
            self::assertContains($e->getCode(), [404, 410]);
        }
    }
}
