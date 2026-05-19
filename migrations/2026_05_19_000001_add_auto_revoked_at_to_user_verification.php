<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * `auto_revoked_at` é o tombstone do opt-out de auto-tier: quando set,
 * `TierResolver` ignora qualquer auto-grant por grupo para este usuário.
 * Sem isso, um usuário verificado via grupo não tinha como revogar o
 * próprio badge — `VerifiedStatus::isVerified` retornava `false`
 * (sem linha companheira) e `unverify` rejeitava com "not verified".
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('user_verification')) return;
        if ($schema->hasColumn('user_verification', 'auto_revoked_at')) return;

        $schema->table('user_verification', function (Blueprint $table) {
            $table->dateTime('auto_revoked_at')->nullable()->after('verified_tier');
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('user_verification')) return;
        if (! $schema->hasColumn('user_verification', 'auto_revoked_at')) return;

        $schema->table('user_verification', function (Blueprint $table) {
            $table->dropColumn('auto_revoked_at');
        });
    },
];
