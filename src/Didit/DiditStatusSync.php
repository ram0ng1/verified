<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Didit;

use Carbon\Carbon;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Ramon\Verified\Event\UserUnverified;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\TierResolver;
use Ramon\Verified\VerifiedStatus;

/**
 * Aplica à solicitação local o status que a Didit informou (webhook ou retorno
 * do usuário). Idempotente: a Didit reenvia webhooks, e o mesmo status pode
 * chegar pelos dois caminhos.
 */
class DiditStatusSync
{
    public const PROVIDER = 'didit';

    public const APPROVED = 'Approved';
    public const DECLINED = 'Declined';
    public const EXPIRED = 'Expired';
    public const ABANDONED = 'Abandoned';
    public const KYC_EXPIRED = 'Kyc Expired';

    public function __construct(
        protected VerifiedStatus $verifiedStatus,
        protected TierResolver $tiers,
        protected TranslatorInterface $translator,
        protected Dispatcher $events
    ) {
    }

    public function apply(VerificationRequest $request, string $status, ?Carbon $now = null): void
    {
        $now ??= Carbon::now();
        $event = null;

        VerificationRequest::runInTransaction(function () use ($request, $status, $now, &$event) {
            // Relê com trava: webhook e retorno do usuário podem chegar juntos.
            /** @var VerificationRequest $locked */
            $locked = VerificationRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            $user = User::query()->find($locked->user_id);

            $locked->external_status = mb_substr($status, 0, 32);

            if ($locked->status === VerificationRequest::STATUS_PENDING) {
                if ($status === self::APPROVED && $user) {
                    $this->close($locked, VerificationRequest::STATUS_APPROVED, 'approved_note', $now);
                    $this->verifiedStatus->mark($user, null, $this->tiers->resolveRequestedTierId(null), $now);
                    $event = new UserVerified($user, $user);
                } elseif (in_array($status, [self::DECLINED, self::EXPIRED, self::ABANDONED], true)) {
                    $this->close($locked, VerificationRequest::STATUS_REJECTED, 'closed_note', $now, ['status' => $status]);
                }
            } elseif ($locked->status === VerificationRequest::STATUS_APPROVED
                && in_array($status, [self::DECLINED, self::KYC_EXPIRED], true)
                && $user
                && $this->isLatestApproval($locked)) {
                // Revisão posterior na Didit ou documento vencido: a verificação
                // deixa de valer.
                $this->close($locked, VerificationRequest::STATUS_REJECTED, 'revoked_note', $now, ['status' => $status]);
                $this->verifiedStatus->clear($user);
                $event = new UserUnverified($user, $user);
            }

            $locked->updated_at = $now;
            $locked->save();

            $request->setRawAttributes($locked->getAttributes(), true);
        });

        if ($event) {
            $this->events->dispatch($event);
        }
    }

    /**
     * @param array<string, string> $params
     */
    private function close(VerificationRequest $request, string $status, string $noteKey, Carbon $now, array $params = []): void
    {
        $request->status = $status;
        $request->handled_by = null;
        $request->handled_at = $now;
        $request->admin_note = $this->translator->trans('ramon-verified.api.didit.'.$noteKey, $params);
    }

    private function isLatestApproval(VerificationRequest $request): bool
    {
        return ! VerificationRequest::query()
            ->where('user_id', $request->user_id)
            ->where('status', VerificationRequest::STATUS_APPROVED)
            ->where('id', '>', $request->getKey())
            ->exists();
    }
}
