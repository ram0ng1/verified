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
 * Limpa `verified_tier` em linhas com `is_verified=false`. Resolver foi
 * endurecido; esta migração só normaliza linhas stale. Migrator passa
 * apenas o `SchemaBuilder` — a conexão sai do próprio schema.
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
