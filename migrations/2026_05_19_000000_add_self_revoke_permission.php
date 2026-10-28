<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Flarum\Group\Group;

/**
 * Default em Members preserva o fluxo em que trocar o avatar obriga o
 * verificado a revogar o próprio badge (`EnforceAvatarLock`).
 */
return Migration::addPermissions([
    'verified.selfRevoke' => Group::MEMBER_ID,
]);
