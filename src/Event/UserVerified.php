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
 * Fired right after an administrator marks a user as verified — either by
 * approving a pending VerificationRequest or by clicking "Verify user"
 * directly from the moderation dropdown.
 *
 * Listeners (notification, email, audit log…) react to this single event
 * regardless of which path the verification came through.
 */
class UserVerified
{
    public function __construct(
        public User $user,
        public User $actor
    ) {
    }
}
