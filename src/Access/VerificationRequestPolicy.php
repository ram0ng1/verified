<?php

namespace Ramon\Verified\Access;

use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;
use Ramon\Verified\Models\VerificationRequest;

class VerificationRequestPolicy extends AbstractPolicy
{
    public function can(User $actor, string $ability, ?VerificationRequest $request = null): ?string
    {
        if ($actor->isAdmin()) {
            return $this->allow();
        }

        return null;
    }

    public function view(User $actor, VerificationRequest $request): ?string
    {
        if ($actor->id === (int) $request->user_id) {
            return $this->allow();
        }

        return null;
    }

    public function delete(User $actor, VerificationRequest $request): ?string
    {
        if ($actor->id === (int) $request->user_id && $request->isPending()) {
            return $this->allow();
        }

        return null;
    }
}
