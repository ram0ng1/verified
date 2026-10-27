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
 * Limpa settings legados de cor que foram substituídos pela config multi-tier.
 * Sem helper `Migration::*` para esse caso — alcança a conexão pelo
 * `SchemaBuilder` (único argumento passado pelo migrator do Flarum).
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
