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

// Solicitações feitas por um provedor externo (Didit): qual provedor, o id da
// sessão lá e o último status que ele informou.
return [
    'up' => function (Builder $schema) {
        $schema->table('verification_requests', function (Blueprint $table) {
            $table->string('provider', 20)->nullable();
            $table->string('external_id', 64)->nullable();
            $table->string('external_status', 32)->nullable();

            $table->unique(['provider', 'external_id']);
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('verification_requests', function (Blueprint $table) {
            $table->dropUnique(['provider', 'external_id']);
            $table->dropColumn(['provider', 'external_id', 'external_status']);
        });
    },
];
