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
 * Sem a FK, apagar o admin deixa `handled_by` apontando para um usuário
 * inexistente. Os IDs órfãos são anulados antes do `addForeign`, senão o
 * `ADD CONSTRAINT` falha em instalações com dados sujos.
 */

$fkExists = function (Builder $schema, string $table, string $name): bool {
    try {
        $fks = $schema->getForeignKeys($table);
    } catch (\Throwable $e) {
        return false;
    }
    foreach ($fks as $fk) {
        if (($fk['name'] ?? null) === $name) {
            return true;
        }
    }
    return false;
};

return [
    'up' => function (Builder $schema) use ($fkExists) {
        if (! $schema->hasTable('verification_requests') || ! $schema->hasColumn('verification_requests', 'handled_by')) {
            return;
        }

        $db = $schema->getConnection();

        $db->table('verification_requests')
            ->whereNotNull('handled_by')
            ->whereNotExists(function ($sub) {
                $sub->from('users')
                    ->whereColumn('users.id', 'verification_requests.handled_by');
            })
            ->update(['handled_by' => null]);

        if (! $fkExists($schema, 'verification_requests', 'verification_requests_handled_by_foreign')) {
            $schema->table('verification_requests', function (Blueprint $table) {
                $table->foreign('handled_by', 'verification_requests_handled_by_foreign')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }
    },
    'down' => function (Builder $schema) use ($fkExists) {
        if (
            $schema->hasTable('verification_requests')
            && $fkExists($schema, 'verification_requests', 'verification_requests_handled_by_foreign')
        ) {
            $schema->table('verification_requests', function (Blueprint $table) {
                $table->dropForeign(['handled_by']);
            });
        }
    },
];
