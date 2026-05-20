<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Audit F4: índice em `verification_requests.handled_by`. MySQL/MariaDB cria
 * um índice implícito ao adicionar a FK (2026_05_18_000000), mas PostgreSQL
 * e SQLite não — sem índice, o pipeline GDPR varre a tabela inteira em
 * `WHERE handled_by = ?` (export/anonymize/delete).
 *
 * Idempotente e portável: pula quando já existe QUALQUER índice cuja
 * primeira coluna é `handled_by` — isso abrange o índice implícito da FK no
 * MySQL, evitando um índice redundante nesse motor, e cria o índice
 * explícito apenas onde ele falta (PostgreSQL/SQLite).
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
