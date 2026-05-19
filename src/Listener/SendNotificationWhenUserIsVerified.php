<?php

namespace Ramon\Verified\Listener;

use Flarum\Notification\NotificationSyncer;
use Psr\Log\LoggerInterface;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\Notification\UserVerifiedBlueprint;
use Throwable;

/**
 * Verify já está commitado quando este listener roda. Falha no pipeline
 * de notification (mailer caído, queue worker offline, erro no blueprint)
 * não pode propagar e desfazer o verify — log e segue.
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
