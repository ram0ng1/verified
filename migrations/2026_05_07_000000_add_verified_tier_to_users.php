<?php

use Flarum\Database\Migration;
use Illuminate\Database\ConnectionInterface;

/**
 * Multi-tier badge: usuário pode ter um de N tiers configurados pelo admin.
 * Verificados pré-existentes recebem o tier default (`blue`) — backfill
 * roda após `addColumns` para que a nova coluna exista no momento do UPDATE.
 */

$base = Migration::addColumns('users', [
    'verified_tier' => ['string', 'length' => 40, 'nullable' => true, 'after' => 'verified_by'],
]);

return [
    'up' => function ($schema) use ($base) {
        $base['up']($schema);

        /** @var ConnectionInterface $db */
        $db = $schema->getConnection();
        $db->table('users')
            ->where('is_verified', true)
            ->whereNull('verified_tier')
            ->update(['verified_tier' => 'blue']);
    },
    'down' => $base['down'],
];
