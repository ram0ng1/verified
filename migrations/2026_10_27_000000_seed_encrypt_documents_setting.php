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
 * A criptografia passou a ser opt-in (`ramon-verified.encrypt_documents`).
 * Fóruns que já tinham gerado o par de chaves continuam criptografando.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $settings = $db->table('settings');

        if ($settings->where('key', 'ramon-verified.encrypt_documents')->exists()) {
            return;
        }

        $publicKey = $db->table('settings')
            ->where('key', 'ramon-verified.encryption_public_key')
            ->value('value');

        $db->table('settings')->insert([
            'key'   => 'ramon-verified.encrypt_documents',
            'value' => is_string($publicKey) && $publicKey !== '' ? '1' : '0',
        ]);
    },
    'down' => function (Builder $schema) {
        $schema->getConnection()->table('settings')
            ->where('key', 'ramon-verified.encrypt_documents')
            ->delete();
    },
];
