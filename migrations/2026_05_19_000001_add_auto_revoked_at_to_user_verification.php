<?php

use Flarum\Database\Migration;

/**
 * `auto_revoked_at` é o tombstone do opt-out de auto-tier: quando set,
 * `TierResolver` ignora qualquer auto-grant por grupo para este usuário.
 * Sem isso, um usuário verificado via grupo não tinha como revogar o
 * próprio badge — `VerifiedStatus::isVerified` retornava `false`
 * (sem linha companheira) e `unverify` rejeitava com "not verified".
 *
 * Usa o helper `Migration::addColumns` (§26) — produz down handler
 * simétrico e guard de coluna existente automaticamente, alinhado com
 * o restante das migrações desta extensão.
 */
return Migration::addColumns('user_verification', [
    'auto_revoked_at' => ['dateTime', 'nullable' => true],
]);
