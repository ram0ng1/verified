<?php

namespace Ramon\Verified\Listener;

use Flarum\Notification\NotificationSyncer;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\Notification\UserVerifiedBlueprint;

class SendNotificationWhenUserIsVerified
{
    public function __construct(
        protected NotificationSyncer $notifications
    ) {
    }

    public function handle(UserVerified $event): void
    {
        $this->notifications->sync(
            new UserVerifiedBlueprint($event->user),
            [$event->user]
        );
    }
}
