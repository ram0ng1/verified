<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Audit F7: FK em `verification_requests.handled_by` para `users.id` com
 * `nullOnDelete`. Sem essa FK, a coluna fica com referência pendurada
 * quando o admin é apagado e a trilha de auditoria mente sobre quem
 * aprovou (linha existe, `User::find($handled_by)` devolve null).
 *
 * `users.verified_by` é coberto pela `2026_05_18_000001` que move a
 * coluna inteira para `user_verification` e cria a FK no `createTable`.
 *
 * Cleanup PRECEDE o `addForeign` — instalações sujas com IDs órfãos
 * fariam o `ADD CONSTRAINT` falhar. Idempotente: checa via
 * `getForeignKeys` (portável Laravel 13: MySQL/MariaDB/PgSQL/SQLite).
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
