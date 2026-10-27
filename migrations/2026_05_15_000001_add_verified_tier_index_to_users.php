<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * No-op preservada apenas para consistência do migration log. A intenção
 * original (índice composto em `users(is_verified, verified_tier)`) foi
 * superada pela companion table `user_verification` (migration
 * `2026_05_18_000001`) — ambos os índices vivem agora no novo schema.
 *
 * Não deletar: forums que rodaram esta migration antes do F1 têm o nome
 * registrado em `migrations`. Removê-la causaria a próxima execução de
 * `migrate` a reaplicar tudo desnecessariamente.
 */
return [
    'up' => fn () => null,
    'down' => fn () => null,
];
