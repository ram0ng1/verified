<?php

namespace Ramon\Verified\Listener;

use Flarum\Notification\NotificationSyncer;
use Illuminate\Support\Facades\Log;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\Notification\UserVerifiedBlueprint;
use Throwable;

class SendNotificationWhenUserIsVerified
{
    public function __construct(
        protected NotificationSyncer $notifications
    ) {
    }

    public function handle(UserVerified $event): void
    {
        // The verification itself is already committed by the time this
        // listener fires. A notification-pipeline failure (mailer down,
        // queue worker offline, blueprint error) must not leak back to
        // the original request and undo the verify.
        try {
            $this->notifications->sync(
                new UserVerifiedBlueprint($event->user),
                [$event->user]
            );
        } catch (Throwable $e) {
            Log::warning('verified: failed to send user-verified notification', [
                'user_id' => (int) $event->user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
