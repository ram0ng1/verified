<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

/**
 * Normaliza linhas antigas com `verified_tier` preenchido e `is_verified=false`.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('users', 'verified_tier')) {
            return;
        }

        $schema->getConnection()
            ->table('users')
            ->where('is_verified', false)
            ->whereNotNull('verified_tier')
            ->update(['verified_tier' => null]);
    },
    'down' => function (Builder $schema) {
    },
];
