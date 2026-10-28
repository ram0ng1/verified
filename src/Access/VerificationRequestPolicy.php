<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Access;

use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;
use Ramon\Verified\Models\VerificationRequest;

class VerificationRequestPolicy extends AbstractPolicy
{
    /**
     * Quem detém `verified.verifyUsers` pode tudo sobre os pedidos: é o mesmo gate
     * do `VerifyUserController`, para um só moderador cuidar das duas frentes.
     */
    public function can(User $actor, string $ability, ?VerificationRequest $request = null): ?string
    {
        if ($actor->hasPermission('verified.verifyUsers')) {
            return $this->allow();
        }

        return null;
    }

    public function view(User $actor, VerificationRequest $request): ?string
    {
        if ((int) $actor->id === (int) $request->user_id) {
            return $this->allow();
        }

        return null;
    }

    public function delete(User $actor, VerificationRequest $request): ?string
    {
        if ((int) $actor->id === (int) $request->user_id && $request->isPending()) {
            return $this->allow();
        }

        return null;
    }
}
