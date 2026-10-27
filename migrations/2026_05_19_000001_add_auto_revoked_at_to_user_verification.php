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

/**
 * Tombstone do opt-out de auto-tier: quando preenchido, o `TierResolver` ignora
 * os auto-grants por grupo. Sem isso, quem é verificado via grupo não consegue
 * revogar o próprio badge.
 */
return Migration::addColumns('user_verification', [
    'auto_revoked_at' => ['dateTime', 'nullable' => true],
]);
