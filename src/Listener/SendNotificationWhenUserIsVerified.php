<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Listener;

use Flarum\Notification\NotificationSyncer;
use Psr\Log\LoggerInterface;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\Notification\UserVerifiedBlueprint;
use Throwable;

/**
 * A verificação já está commitada: uma falha na notificação (mailer, fila,
 * blueprint) não pode propagar, então loga e segue.
 */
class SendNotificationWhenUserIsVerified
{
    public function __construct(
        protected NotificationSyncer $notifications,
        protected LoggerInterface $logger
    ) {
    }

    public function handle(UserVerified $event): void
    {
        try {
            $this->notifications->sync(
                new UserVerifiedBlueprint($event->user),
                [$event->user]
            );
        } catch (Throwable $e) {
            $this->logger->warning('verified: failed to send user-verified notification', [
                'user_id' => (int) $event->user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
