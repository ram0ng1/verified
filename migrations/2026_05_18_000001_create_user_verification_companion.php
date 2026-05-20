<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Move o estado verified de `users` para `user_verification`. Copia dados
 * existentes antes de dropar as colunas e índices legados. Idempotente.
 */

$indexExists = function (Builder $schema, string $table, string $name): bool {
    try {
        $indexes = $schema->getIndexes($table);
    } catch (\Throwable $e) {
        return false;
    }
    foreach ($indexes as $idx) {
        if (($idx['name'] ?? null) === $name) {
            return true;
        }
    }
    return false;
};

$base = Migration::createTableIfNotExists('user_verification', function (Blueprint $table) {
    $table->unsignedInteger('user_id')->primary();
    $table->boolean('is_verified')->default(false);
    $table->dateTime('verified_at')->nullable();
    $table->unsignedInteger('verified_by')->nullable();
    $table->string('verified_tier', 40)->nullable();

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('verified_by', 'user_verification_verified_by_foreign')
        ->references('id')->on('users')->nullOnDelete();
    $table->index(['is_verified', 'verified_tier'], 'user_verification_is_verified_tier_index');
});

return [
    'up' => function (Builder $schema) use ($base, $indexExists) {
        $base['up']($schema);

        $db = $schema->getConnection();

        if (
            $schema->hasTable('users')
            && $schema->hasColumn('users', 'is_verified')
            && $schema->hasColumn('users', 'verified_at')
            && $schema->hasColumn('users', 'verified_by')
        ) {
            /*
             * `chunkById` em vez de `cursor()`: o cursor abre um result set
             * não-bufferizado e, no MySQL/PDO, gravar (`updateOrInsert`) na
             * mesma conexão enquanto ele está aberto lança "Cannot execute
             * queries while other unbuffered queries are active". O chunk
             * bufferiza cada lote e libera a conexão para os writes.
             */
            $db->table('users')
                ->select(['id', 'is_verified', 'verified_at', 'verified_by', 'verified_tier'])
                ->where(function ($q) {
                    $q->where('is_verified', true)->orWhereNotNull('verified_tier');
                })
                ->chunkById(500, function ($rows) use ($db) {
                    foreach ($rows as $row) {
                        $db->table('user_verification')->updateOrInsert(
                            ['user_id' => (int) $row->id],
                            [
                                'is_verified'   => (bool) ($row->is_verified ?? false),
                                'verified_at'   => $row->verified_at,
                                'verified_by'   => $row->verified_by !== null ? (int) $row->verified_by : null,
                                'verified_tier' => $row->verified_tier,
                            ]
                        );
                    }
                }, 'id');

            if ($indexExists($schema, 'users', 'users_is_verified_verified_tier_index')) {
                $schema->table('users', function (Blueprint $table) {
                    $table->dropIndex('users_is_verified_verified_tier_index');
                });
            }

            if ($indexExists($schema, 'users', 'users_verified_by_foreign')) {
                $schema->table('users', function (Blueprint $table) {
                    $table->dropForeign('users_verified_by_foreign');
                });
            }

            $schema->table('users', function (Blueprint $table) {
                $table->dropColumn(['is_verified', 'verified_at', 'verified_by', 'verified_tier']);
            });
        }
    },
    'down' => function (Builder $schema) use ($base) {
        if (
            $schema->hasTable('users')
            && ! $schema->hasColumn('users', 'is_verified')
        ) {
            $schema->table('users', function (Blueprint $table) {
                $table->boolean('is_verified')->default(false);
                $table->dateTime('verified_at')->nullable();
                $table->unsignedInteger('verified_by')->nullable();
                $table->string('verified_tier', 40)->nullable();
            });

            $db = $schema->getConnection();
            $db->table('user_verification')
                ->chunkById(500, function ($rows) use ($db) {
                    foreach ($rows as $row) {
                        $db->table('users')
                            ->where('id', (int) $row->user_id)
                            ->update([
                                'is_verified'   => (bool) $row->is_verified,
                                'verified_at'   => $row->verified_at,
                                'verified_by'   => $row->verified_by !== null ? (int) $row->verified_by : null,
                                'verified_tier' => $row->verified_tier,
                            ]);
                    }
                }, 'user_id');
        }

        $base['down']($schema);
    },
];
