<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Índice para a consulta mais quente: `WHERE user_id = ? AND status = 'pending'`.
 * `getIndexes()` mantém a migração idempotente em MySQL, PostgreSQL e SQLite.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('verification_requests')) {
            return;
        }

        $indexes = collect($schema->getIndexes('verification_requests'));
        $alreadyExists = $indexes->contains(function ($index) {
            $cols = $index['columns'] ?? [];
            return is_array($cols)
                && count($cols) === 2
                && $cols[0] === 'user_id'
                && $cols[1] === 'status';
        });
        if ($alreadyExists) {
            return;
        }

        $schema->table('verification_requests', function (Blueprint $table) {
            $table->index(['user_id', 'status'], 'verification_requests_user_id_status_index');
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('verification_requests')) {
            return;
        }

        $indexes = collect($schema->getIndexes('verification_requests'));
        $exists = $indexes->contains(fn ($i) => ($i['name'] ?? null) === 'verification_requests_user_id_status_index');
        if (! $exists) {
            return;
        }

        $schema->table('verification_requests', function (Blueprint $table) {
            $table->dropIndex('verification_requests_user_id_status_index');
        });
    },
];
