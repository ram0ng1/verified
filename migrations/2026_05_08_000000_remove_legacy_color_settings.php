<?php

use Flarum\Database\Migration;
use Illuminate\Database\ConnectionInterface;

return [
    'up' => function (ConnectionInterface $db) {
        $db->table('settings')
            ->whereIn('key', [
                'ramon-verified.custom_color_enabled',
                'ramon-verified.badge_color',
            ])
            ->delete();
    },
    'down' => function () {
        // No rollback — defaults are restored on extension boot if reintroduced.
    },
];
