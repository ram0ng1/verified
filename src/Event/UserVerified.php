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
 * Disparado quando o usuário é verificado, seja aprovando uma solicitação ou
 * direto pelo moderador.
 */
class UserVerified
{
    public function __construct(
        public User $user,
        public User $actor
    ) {
    }
}
