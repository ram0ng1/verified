<?php

namespace Ramon\Verified\Access;

use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;
use Ramon\Verified\Models\VerificationRequest;

class VerificationRequestPolicy extends AbstractPolicy
{
    /**
     * Catch-all: quem detém `verified.verifyUsers` pode tudo sobre os
     * pedidos — listar, ver, aprovar, rejeitar, revogar, deletar. Espelha o
     * gate de `VerifyUserController` para que o mesmo moderador trate tanto
     * a verificação direta quanto a fila de pedidos. Admins têm todas as
     * permissões, então `hasPermission` os cobre sem um teste explícito.
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
