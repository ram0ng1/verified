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

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Ramon\Verified\Models\UserVerification;

/**
 * Verificação e revogação diretas (`/api/verified/users/{id}/verify`) e os
 * campos que o fórum lê.
 */
class DirectVerificationTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ramon-verified');

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
        ]);
    }

    private function body(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    public function test_admin_verifica_e_o_usuario_revoga_o_proprio(): void
    {
        $verified = $this->send($this->request('POST', '/api/verified/users/2/verify', ['authenticatedAs' => 1, 'json' => []]));

        $this->assertEquals(200, $verified->getStatusCode(), (string) $verified->getBody());
        $this->assertTrue($this->body($verified)['data']['attributes']['isVerified']);

        $revoked = $this->send($this->request('DELETE', '/api/verified/users/2/verify', ['authenticatedAs' => 2, 'json' => []]));

        $this->assertEquals(200, $revoked->getStatusCode(), (string) $revoked->getBody());
        $this->assertNull(UserVerification::query()->find(2));
    }

    public function test_membro_nao_verifica_ninguem(): void
    {
        $response = $this->send($this->request('POST', '/api/verified/users/2/verify', ['authenticatedAs' => 2, 'json' => []]));

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_campos_do_usuario_e_do_forum(): void
    {
        $this->send($this->request('POST', '/api/verified/users/2/verify', ['authenticatedAs' => 1, 'json' => []]));

        $user = $this->body($this->send($this->request('GET', '/api/users/2', ['authenticatedAs' => 2])));
        $this->assertTrue($user['data']['attributes']['isVerified']);
        $this->assertFalse($user['data']['attributes']['canRequestVerification']);
        $this->assertFalse($user['data']['attributes']['hasPendingVerificationRequest']);

        $forum = $this->body($this->send($this->request('GET', '/api')));
        $this->assertNotEmpty($forum['data']['attributes']['ramonVerifiedTiers']);
        $this->assertSame('blue', $forum['data']['attributes']['ramonVerifiedTiers'][0]['id']);
    }

    public function test_pendente_aparece_nos_campos(): void
    {
        $before = $this->body($this->send($this->request('GET', '/api/users/2', ['authenticatedAs' => 2])));
        $this->assertTrue($before['data']['attributes']['canRequestVerification']);

        \Ramon\Verified\Models\VerificationRequest::query()->insert([
            'user_id' => 2, 'status' => 'pending', 'created_at' => \Carbon\Carbon::now(), 'updated_at' => \Carbon\Carbon::now(),
        ]);

        $after = $this->body($this->send($this->request('GET', '/api/users/2', ['authenticatedAs' => 2])));
        $this->assertFalse($after['data']['attributes']['canRequestVerification']);
        $this->assertTrue($after['data']['attributes']['hasPendingVerificationRequest']);
    }
}
