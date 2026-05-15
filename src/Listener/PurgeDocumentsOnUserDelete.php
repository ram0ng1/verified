<?php

namespace Ramon\Verified\Listener;

use Flarum\User\Event\Deleting;
use Flarum\User\User;
use Psr\Log\LoggerInterface;
use Ramon\Verified\Documents\DocumentRetention;
use Throwable;

/**
 * Wipe every document file owned by a user being hard-deleted.
 *
 * Without this hook, the `verification_requests.user_id` foreign key's
 * `onDelete('cascade')` silently removes request rows at the DB layer —
 * Eloquent does NOT dispatch model events for FK cascades, so the
 * existing `PurgeDocumentOnRequestDelete` listener never fires and the
 * files orphan in `storage/verified-documents/{userId}/`.
 *
 * Two registration paths (extend.php) reach this listener:
 *   - `UserDeleting::class` (Flarum's high-level event, dispatched ONLY
 *     by `UserResource::deleting()`) — covers API deletion.
 *   - `eloquent.deleting: User::class` (Eloquent's model-level event,
 *     fires for EVERY `$user->delete()` regardless of caller) — covers
 *     tinker, CLI, and any future non-API admin tooling. Audit N6.
 *
 * The Flarum event carries `(User $user, User $actor, array $data)`;
 * Eloquent passes the model itself. Accept either via a union type
 * and resolve the user reference accordingly.
 */
class PurgeDocumentsOnUserDelete
{
    public function __construct(
        protected DocumentRetention $retention,
        protected LoggerInterface $logger
    ) {
    }

    public function handle(Deleting|User $event): void
    {
        $user = $event instanceof Deleting ? $event->user : $event;
        $userId = (int) $user->id;

        if ($userId <= 0) {
            return;
        }

        try {
            $this->retention->purgeAllForUser($userId);
        } catch (Throwable $e) {
            // FS failures must not block the user delete — log and move on.
            // Files orphan rather than blocking the GDPR/admin action.
            $this->logger->warning('verified: failed to purge documents on user delete', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
