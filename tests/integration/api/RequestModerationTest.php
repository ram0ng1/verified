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
            User::class => [$this->normalUser()],
            VerificationRequest::class => [
                ['id' => 1, 'user_id' => 2, 'status' => 'pending', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
                ['id' => 2, 'user_id' => 2, 'status' => 'rejected', 'handled_by' => 1, 'admin_note' => 'original', 'created_at' => Carbon::now()->subDay(), 'updated_at' => Carbon::now()->subDay(), 'handled_at' => Carbon::now()->subDay()],
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
}
