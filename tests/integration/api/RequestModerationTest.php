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
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Ramon\Verified\Models\UserVerification;
use Ramon\Verified\Models\VerificationRequest;

/**
 * Aprovar, rejeitar e revogar pela fila de solicitações.
 */
class RequestModerationTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-verified');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'outro', 'email' => 'outro@machine.local', 'is_email_confirmed' => 1],
            ],
            VerificationRequest::class => [
                ['id' => 1, 'user_id' => 2, 'status' => 'pending', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
                ['id' => 2, 'user_id' => 2, 'status' => 'rejected', 'handled_by' => 1, 'admin_note' => 'original', 'created_at' => Carbon::now()->subDay(), 'updated_at' => Carbon::now()->subDay(), 'handled_at' => Carbon::now()->subDay()],
                // Usuário 3: aprovado (4), revogado direto e verificado direto de novo (5).
                ['id' => 4, 'user_id' => 3, 'status' => 'approved', 'handled_by' => 1, 'created_at' => Carbon::now()->subDays(3), 'updated_at' => Carbon::now()->subDays(3), 'handled_at' => Carbon::now()->subDays(3)],
                ['id' => 5, 'user_id' => 3, 'status' => 'approved', 'handled_by' => 1, 'created_at' => Carbon::now()->subDay(), 'updated_at' => Carbon::now()->subDay(), 'handled_at' => Carbon::now()->subDay()],
            ],
            UserVerification::class => [
                ['user_id' => 3, 'is_verified' => true, 'verified_at' => Carbon::now()->subDay(), 'verified_by' => 1],
            ],
        ]);
    }

    private function post(int $id, string $action): \Psr\Http\Message\ResponseInterface
    {
        return $this->send($this->request('POST', "/api/verification-requests/$id/$action", [
            'authenticatedAs' => 1,
            'json' => ['meta' => []],
        ]));
    }

    public function test_revogar_pendente_e_recusado(): void
    {
        $response = $this->post(1, 'revoke');

        $this->assertEquals(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertEquals('pending', VerificationRequest::query()->find(1)->status);
    }

    /** Revogar uma linha antiga reescrevia quem a tratou e a nota. */
    public function test_revogar_rejeitada_nao_reescreve_historico(): void
    {
        $response = $this->post(2, 'revoke');

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertEquals('original', VerificationRequest::query()->find(2)->admin_note);
    }

    public function test_aprovar_depois_de_rejeitar_e_recusado(): void
    {
        $this->assertEquals(200, $this->post(1, 'reject')->getStatusCode());

        $response = $this->post(1, 'approve');

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertEquals('rejected', VerificationRequest::query()->find(1)->status);
    }

    public function test_revogar_aprovada_funciona(): void
    {
        $this->assertEquals(200, $this->post(1, 'approve')->getStatusCode());

        $response = $this->post(1, 'revoke');

        $this->assertEquals(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertEquals('rejected', VerificationRequest::query()->find(1)->status);
    }

    /** A aprovação antiga não pode apagar a verificação que está valendo. */
    public function test_revogar_aprovacao_antiga_e_recusado(): void
    {
        $response = $this->post(4, 'revoke');

        $this->assertEquals(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('ramon-verified.api.revoke_not_latest', (string) $response->getBody());
        $this->assertTrue((bool) UserVerification::query()->find(3)->is_verified);
    }

    public function test_revogar_aprovacao_atual_funciona(): void
    {
        $this->assertEquals(200, $this->post(5, 'revoke')->getStatusCode());
        $this->assertNull(UserVerification::query()->find(3));
    }
}
