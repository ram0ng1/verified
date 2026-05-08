<?php

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        $schema->getConnection()
            ->table('settings')
            ->whereIn('key', [
                'ramon-verified.custom_color_enabled',
                'ramon-verified.badge_color',
            ])
            ->delete();
    },
    'down' => function (Builder $schema) {
        // No rollback — defaults are restored on extension boot if reintroduced.
    },
];
