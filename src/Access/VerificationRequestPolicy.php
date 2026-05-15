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
        // Strict integer compare on BOTH sides (CLAUDE.md §3 — `null == 0`
        // is true in PHP, and depending on the DB driver `$actor->id` can
        // come back as a numeric string).
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
