<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Extend;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Ramon\Verified\Models\UserVerification;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\Tests\Support\FakeDiditClient;
use Ramon\Verified\Tests\Support\FakeDiditServiceProvider;

/**
 * Integração com a Didit, com a API simulada (a real fica em tests/live).
 */
class DiditTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const SECRET = 'whsec-teste';

    protected function setUp(): void
    {
        parent::setUp();

        FakeDiditClient::reset();

        $this->extension('ramon-verified');
        $this->extend((new Extend\ServiceProvider())->register(FakeDiditServiceProvider::class));

        $this->setting('ramon-verified.didit_enabled', '1');
        $this->setting('ramon-verified.didit_api_key', 'sk_test_fake');
        $this->setting('ramon-verified.didit_webhook_secret', self::SECRET);

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
        ]);
    }

    private function body(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function startSession(): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/verified/didit/session', ['authenticatedAs' => 2, 'json' => []]));
    }

    private function webhook(array $payload, ?string $secret = self::SECRET): ResponseInterface
    {
        $raw = json_encode($payload);
        $stream = new Stream('php://temp', 'r+');
        $stream->write($raw);
        $stream->rewind();

        $request = $this->request('POST', '/api/verified/didit/webhook')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Timestamp', (string) time())
            ->withHeader('X-Signature', hash_hmac('sha256', $raw, (string) $secret))
            ->withBody($stream);

        return $this->send($request);
    }

    private function statusEvent(string $status): array
    {
        return ['webhook_type' => 'status.updated', 'session_id' => FakeDiditClient::SESSION_ID, 'status' => $status, 'timestamp' => time()];
    }

    public function test_abre_a_sessao_e_registra_a_solicitacao_pendente(): void
    {
        $response = $this->startSession();

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('https://verify.didit.me/session/fake', $this->body($response)['url']);

        $request = VerificationRequest::query()->where('user_id', 2)->firstOrFail();
        $this->assertSame('pending', $request->status);
        $this->assertSame('didit', $request->provider);
        $this->assertSame(FakeDiditClient::SESSION_ID, $request->external_id);

        $posts = array_values(array_filter(FakeDiditClient::$calls, fn ($c) => $c['method'] === 'POST'));
        $sent = $posts[0]['body'];
        $this->assertSame('flarum-user-2', $sent['vendor_data']);
        // Sem workflow escolhido no admin, vai o padrão da conta.
        $this->assertSame('b2093113-38ea-43ae-9d5c-113f32fe0c95', $sent['workflow_id']);
        $this->assertStringContainsString('/verified/didit/return', $sent['callback']);
    }

    /** Clicar de novo retoma a mesma sessão, sem duplicar a solicitação. */
    public function test_clicar_de_novo_retoma_a_sessao(): void
    {
        $this->startSession();
        $this->assertEquals(200, $this->startSession()->getStatusCode());

        $this->assertSame(1, VerificationRequest::query()->where('user_id', 2)->count());
    }

    public function test_desligada_nao_abre_sessao(): void
    {
        $this->setting('ramon-verified.didit_enabled', '0');

        $this->assertEquals(422, $this->startSession()->getStatusCode());
        $this->assertSame([], FakeDiditClient::$calls);
    }

    public function test_didit_fora_do_ar_nao_cria_solicitacao(): void
    {
        FakeDiditClient::$responses['POST /v3/session/'] = 503;

        $response = $this->startSession();

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertStringContainsString('ramon-verified.api.didit.unavailable', (string) $response->getBody());
        $this->assertSame(0, VerificationRequest::query()->count());
    }

    /** Ligada, a solicitação manual e o upload de documento ficam fechados. */
    public function test_fluxo_manual_fica_fechado(): void
    {
        $manual = $this->send($this->request('POST', '/api/verification-requests', [
            'authenticatedAs' => 2,
            'json' => ['data' => ['type' => 'verification-requests', 'attributes' => ['reason' => 'manual']]],
        ]));

        $this->assertEquals(422, $manual->getStatusCode());
        $this->assertStringContainsString('ramon-verified.api.didit.required', (string) $manual->getBody());
    }

    public function test_webhook_aprovado_verifica_o_usuario(): void
    {
        $this->startSession();

        $response = $this->webhook($this->statusEvent('Approved'));

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('approved', VerificationRequest::query()->firstOrFail()->status);
        $this->assertTrue((bool) UserVerification::query()->find(2)?->is_verified);
    }

    /** A Didit reenvia webhooks: o mesmo status duas vezes não muda nada. */
    public function test_webhook_repetido_e_idempotente(): void
    {
        $this->startSession();
        $this->webhook($this->statusEvent('Approved'));
        $this->webhook($this->statusEvent('Approved'));

        $this->assertSame(1, VerificationRequest::query()->count());
        $this->assertSame('approved', VerificationRequest::query()->firstOrFail()->status);
    }

    public function test_webhook_recusado_rejeita(): void
    {
        $this->startSession();
        $this->webhook($this->statusEvent('Declined'));

        $request = VerificationRequest::query()->firstOrFail();
        $this->assertSame('rejected', $request->status);
        $this->assertSame('Declined', $request->external_status);
        $this->assertNull(UserVerification::query()->find(2));
    }

    public function test_em_revisao_continua_pendente(): void
    {
        $this->startSession();
        $this->webhook($this->statusEvent('In Review'));

        $request = VerificationRequest::query()->firstOrFail();
        $this->assertSame('pending', $request->status);
        $this->assertSame('In Review', $request->external_status);
    }

    /** Documento vencido depois da aprovação: a verificação deixa de valer. */
    public function test_kyc_expirado_revoga(): void
    {
        $this->startSession();
        $this->webhook($this->statusEvent('Approved'));
        $this->webhook($this->statusEvent('Kyc Expired'));

        $this->assertSame('rejected', VerificationRequest::query()->firstOrFail()->status);
        $this->assertNull(UserVerification::query()->find(2));
    }

    public function test_webhook_com_assinatura_invalida_e_recusado(): void
    {
        $this->startSession();

        $response = $this->webhook($this->statusEvent('Approved'), 'segredo-errado');

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertSame('pending', VerificationRequest::query()->firstOrFail()->status);
    }

    public function test_webhook_de_sessao_desconhecida_e_ignorado(): void
    {
        $response = $this->webhook($this->statusEvent('Approved'));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($this->body($response)['ignored']);
        $this->assertNull(UserVerification::query()->find(2));
    }

    /** O moderador não decide uma sessão da Didit pelo fórum. */
    public function test_admin_nao_aprova_sessao_da_didit(): void
    {
        $this->startSession();
        $id = VerificationRequest::query()->firstOrFail()->id;

        $response = $this->send($this->request('POST', "/api/verification-requests/$id/approve", ['authenticatedAs' => 1, 'json' => ['meta' => []]]));

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertStringContainsString('ramon-verified.api.didit.managed', (string) $response->getBody());
    }

    /** No retorno, o status vem da API da Didit, não da query string. */
    public function test_retorno_busca_a_decisao_na_api(): void
    {
        $this->startSession();

        $response = $this->send($this->request('GET', '/verified/didit/return', ['authenticatedAs' => 2])
            ->withQueryParams(['verificationSessionId' => FakeDiditClient::SESSION_ID, 'status' => 'Declined']));

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertSame('approved', VerificationRequest::query()->firstOrFail()->status);
        $this->assertTrue((bool) UserVerification::query()->find(2)?->is_verified);
    }

    /** A sessão de outra pessoa não é consultada no retorno. */
    public function test_retorno_ignora_sessao_de_outro_usuario(): void
    {
        $this->startSession();

        $response = $this->send($this->request('GET', '/verified/didit/return', ['authenticatedAs' => 1])
            ->withQueryParams(['verificationSessionId' => FakeDiditClient::SESSION_ID]));

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertSame('pending', VerificationRequest::query()->firstOrFail()->status);
    }

    public function test_admin_lista_os_workflows(): void
    {
        $response = $this->send($this->request('GET', '/api/verified/didit/workflows', ['authenticatedAs' => 1]));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertSame('Basic KYC', $this->body($response)['workflows'][0]['label']);
    }

    public function test_membro_nao_lista_os_workflows(): void
    {
        $response = $this->send($this->request('GET', '/api/verified/didit/workflows', ['authenticatedAs' => 2]));

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_workflow_invalido_nao_e_salvo(): void
    {
        $response = $this->send($this->request('POST', '/api/settings', [
            'authenticatedAs' => 1,
            'json' => ['ramon-verified.didit_workflow_id' => 'nao-e-uuid'],
        ]));

        $this->assertEquals(422, $response->getStatusCode());
    }

    public function test_chave_nao_vai_para_o_forum(): void
    {
        $forum = (string) $this->send($this->request('GET', '/api', ['authenticatedAs' => 2]))->getBody();

        $this->assertStringNotContainsString('sk_test_fake', $forum);
        $this->assertStringNotContainsString(self::SECRET, $forum);
        $this->assertTrue(json_decode($forum, true)['data']['attributes']['ramonVerifiedDiditEnabled']);
    }
}
