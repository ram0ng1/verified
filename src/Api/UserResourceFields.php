<?php

namespace Ramon\Verified\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Ramon\Verified\Models\VerificationRequest;

class UserResourceFields
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('isVerified')
                ->get(fn (User $user) => $this->isVerified($user)),

            Schema\DateTime::make('verifiedAt')
                ->property('verified_at')
                ->nullable(),

            Schema\Boolean::make('canRequestVerification')
                ->get(function (User $user, Context $context) {
                    $actor = $context->getActor();

                    if ($actor->isGuest() || $actor->id !== $user->id) {
                        return false;
                    }

                    if ($this->isVerified($user)) {
                        return false;
                    }

                    if (! $actor->hasPermission('verified.request')) {
                        return false;
                    }

                    // Admin can close intake of new requests entirely.
                    if (! (bool) $this->settings->get('ramon-verified.requests_open', true)) {
                        return false;
                    }

                    $hasPending = VerificationRequest::query()
                        ->where('user_id', $user->id)
                        ->where('status', VerificationRequest::STATUS_PENDING)
                        ->exists();

                    return ! $hasPending;
                }),

            Schema\Boolean::make('hasPendingVerificationRequest')
                ->get(function (User $user, Context $context) {
                    $actor = $context->getActor();

                    if ($actor->isGuest() || ($actor->id !== $user->id && ! $actor->isAdmin())) {
                        return false;
                    }

                    return VerificationRequest::query()
                        ->where('user_id', $user->id)
                        ->where('status', VerificationRequest::STATUS_PENDING)
                        ->exists();
                }),

            Schema\Boolean::make('isAvatarLocked')
                ->get(function (User $user, Context $context) {
                    $actor = $context->getActor();

                    if ($actor->isGuest() || $actor->id !== $user->id) {
                        return false;
                    }

                    if ((bool) $actor->isAdmin()) {
                        return false;
                    }

                    if (! (bool) $this->settings->get('ramon-verified.lock_avatar')) {
                        return false;
                    }

                    return (bool) $user->is_verified;
                }),
        ];
    }

    /**
     * A user is "verified" when they were explicitly verified by an admin
     * OR when they belong to a group that grants the auto-verify permission.
     */
    protected function isVerified(User $user): bool
    {
        if ((bool) $user->is_verified) {
            return true;
        }

        return $user->hasPermission('verified.autoVerified');
    }
}
