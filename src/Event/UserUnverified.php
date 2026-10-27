<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Event;

use Flarum\User\User;

/**
 * Fired right after an administrator removes a user's verified status —
 * either by revoking via the moderation dropdown (DELETE /verify) or by
 * revoking a handled VerificationRequest from the admin list.
 *
 * Mirrors UserVerified so listeners (audit log, webhooks…) can react to the
 * verified-status lifecycle through a single pair of events regardless of
 * which path the change came through.
 */
class UserUnverified
{
    public function __construct(
        public User $user,
        public User $actor
    ) {
    }
}
