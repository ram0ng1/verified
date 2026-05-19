<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Audit M-index: índice composto em `verification_requests(user_id, status)`.
 * O caminho mais quente é "tem request pendente?" — `WHERE user_id=? AND
 * status='pending'`. MySQL/MariaDB usa um único índice por condição;
 * sem composto, fica filtrando no buffer pool conforme a tabela cresce.
 * Idempotente: `getIndexes()` é portável em MySQL / PostgreSQL / SQLite.
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
