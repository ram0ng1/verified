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
 * PostgreSQL e SQLite não criam índice implícito para a FK de `handled_by`, e o
 * pipeline GDPR filtra por essa coluna. Pula se já existir um índice começando
 * em `handled_by` (o implícito do MySQL, por exemplo).
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('verification_requests') || ! $schema->hasColumn('verification_requests', 'handled_by')) {
            return;
        }

        $indexes = collect($schema->getIndexes('verification_requests'));
        $alreadyIndexed = $indexes->contains(function ($index) {
            $cols = $index['columns'] ?? [];
            return is_array($cols) && ($cols[0] ?? null) === 'handled_by';
        });
        if ($alreadyIndexed) {
            return;
        }

        $schema->table('verification_requests', function (Blueprint $table) {
            $table->index('handled_by', 'verification_requests_handled_by_index');
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('verification_requests')) {
            return;
        }

        $indexes = collect($schema->getIndexes('verification_requests'));
        $exists = $indexes->contains(fn ($i) => ($i['name'] ?? null) === 'verification_requests_handled_by_index');
        if (! $exists) {
            return;
        }

        $schema->table('verification_requests', function (Blueprint $table) {
            $table->dropIndex('verification_requests_handled_by_index');
        });
    },
];
