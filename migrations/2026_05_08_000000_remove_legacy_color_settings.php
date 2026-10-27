<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

/**
 * Remove settings de cor substituídos pela config multi-tier. A conexão vem do
 * `SchemaBuilder`, único argumento que o migrator do Flarum passa.
 */
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
    },
];
