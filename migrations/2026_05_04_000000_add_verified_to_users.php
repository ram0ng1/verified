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

return Migration::addColumns('users', [
    'is_verified' => ['boolean', 'default' => false],
    'verified_at' => ['dateTime', 'nullable' => true],
    'verified_by' => ['integer', 'unsigned' => true, 'nullable' => true],
]);
