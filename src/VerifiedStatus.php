<?php

namespace Ramon\Verified;

use Carbon\Carbon;
use Flarum\User\User;
use Ramon\Verified\Models\UserVerification;

/**
 * Fachada para o estado de verificação que vive na tabela companheira
 * `user_verification`. Concentra reads e writes para que os call-sites
 * não precisem manipular a relação Eloquent diretamente (e para que a
 * regra de "criar linha quando precisa, deletar quando zera" fique em um
 * lugar só).
 *
 * `read()` retorna sempre uma instância (nova vazia quando não há linha)
 * para o caller poder ler propriedades sem chequer null. `mark()`/`clear()`
 * persistem o estado.
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
     * Apaga a linha companheira. Apagar (em vez de zerar) mantém a tabela
     * pequena — usuários não-verificados não pagam aluguel de uma linha
     * com todos os campos null. Também limpa qualquer opt-out de auto-tier
     * existente — `clear` é "voltar ao estado original".
     */
    public function clear(User $user): void
    {
        UserVerification::query()->where('user_id', $user->id)->delete();
        $user->setRelation('verification', null);
    }

    /**
     * Tombstone do opt-out: persiste uma linha com `is_verified=false` e
     * `auto_revoked_at` setado. `TierResolver` honra esse marcador para
     * não devolver auto-tier por grupo. Usado quando um usuário verificado
     * apenas via auto-grant clica em Revoke — não há row pra deletar, mas
     * precisamos registrar a intenção.
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
     * Carrega a linha companheira; devolve instância vazia (não persistida)
     * quando o usuário ainda não foi verificado. Memoiza o `null` via
     * `setRelation` para que reads subsequentes no mesmo request não
     * emitam novas queries.
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
