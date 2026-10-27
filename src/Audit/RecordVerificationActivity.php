<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Audit;

use Flarum\User\User;
use Psr\Log\LoggerInterface;
use Ramon\Audit\Activity\Recorder;
use Ramon\Verified\Event\UserUnverified;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\TierResolver;
use Throwable;

/**
 * Só é registrado com ramon/audit instalada (guard `class_exists` no extend.php),
 * então `Recorder` nunca é autoloadado sem ela. Uma falha aqui não pode desfazer
 * o verify/unverify já commitado: loga e segue.
 */
class RecordVerificationActivity
{
    public function __construct(
        protected Recorder $recorder,
        protected TierResolver $tiers,
        protected LoggerInterface $logger
    ) {
    }

    public function whenVerified(UserVerified $event): void
    {
        $this->record('verified.user.verified', $event->user, $event->actor, [
            'tier' => $this->tiers->resolveTierId($event->user),
        ]);
    }

    public function whenUnverified(UserUnverified $event): void
    {
        $this->record('verified.user.unverified', $event->user, $event->actor, []);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function record(string $type, User $user, User $actor, array $payload): void
    {
        try {
            $this->recorder->record(
                type: $type,
                actor: $actor,
                target: $user,
                payload: $payload,
            );
        } catch (Throwable $e) {
            $this->logger->warning('verified: failed to record audit activity', [
                'type'    => $type,
                'user_id' => (int) $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
