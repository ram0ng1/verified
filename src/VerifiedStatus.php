<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified;

use Carbon\Carbon;
use Flarum\User\User;
use Ramon\Verified\Models\UserVerification;

/**
 * Leituras e escritas do estado em `user_verification`, para a regra de criar a
 * linha quando preciso e apagá-la ao zerar ficar num lugar só. `read()` sempre
 * devolve uma instância, vazia quando não há linha.
 */
class VerifiedStatus
{
    public function isVerified(User $user): bool
    {
        return (bool) $this->read($user)->is_verified;
    }

    public function verifiedAt(User $user): ?Carbon
    {
        $v = $this->read($user)->verified_at;
        return $v instanceof Carbon ? $v : null;
    }

    public function verifiedBy(User $user): ?int
    {
        $v = $this->read($user)->verified_by;
        return $v === null ? null : (int) $v;
    }

    public function manualTier(User $user): ?string
    {
        $v = $this->read($user)->verified_tier;
        return is_string($v) && $v !== '' ? $v : null;
    }

    public function mark(User $user, ?int $adminId, ?string $tier, Carbon $when): void
    {
        $row = $this->read($user);
        $row->user_id = (int) $user->id;
        $row->is_verified = true;
        $row->verified_at = $when;
        $row->verified_by = $adminId;
        $row->verified_tier = $tier;
        $row->auto_revoked_at = null;
        $row->save();

        $user->setRelation('verification', $row);
    }

    /**
     * Apagar em vez de zerar mantém a tabela pequena. Também remove qualquer opt-out
     * de auto-tier: volta ao estado original.
     */
    public function clear(User $user): void
    {
        UserVerification::query()->where('user_id', $user->id)->delete();
        $user->setRelation('verification', null);
    }

    /**
     * Verificado só por auto-grant não tem linha para apagar, então o Revoke grava
     * `is_verified=false` com `auto_revoked_at`, que o `TierResolver` respeita.
     */
    public function markAutoRevoked(User $user, Carbon $when): void
    {
        $row = $this->read($user);
        $row->user_id = (int) $user->id;
        $row->is_verified = false;
        $row->verified_at = null;
        $row->verified_by = null;
        $row->verified_tier = null;
        $row->auto_revoked_at = $when;
        $row->save();

        $user->setRelation('verification', $row);
    }

    public function autoRevokedAt(User $user): ?Carbon
    {
        $v = $this->read($user)->auto_revoked_at;
        return $v instanceof Carbon ? $v : null;
    }

    /**
     * Memoiza a ausência via `setRelation` para não repetir a query no mesmo request.
     */
    private function read(User $user): UserVerification
    {
        if ($user->relationLoaded('verification')) {
            $relation = $user->getRelation('verification');
            if ($relation instanceof UserVerification) {
                return $relation;
            }

            $empty = new UserVerification();
            $empty->user_id = (int) $user->id;
            return $empty;
        }

        $row = UserVerification::query()->where('user_id', $user->id)->first();
        $user->setRelation('verification', $row instanceof UserVerification ? $row : null);

        if ($row instanceof UserVerification) {
            return $row;
        }

        $empty = new UserVerification();
        $empty->user_id = (int) $user->id;
        return $empty;
    }

}
