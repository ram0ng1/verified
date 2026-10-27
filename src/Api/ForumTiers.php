<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Api;

use Illuminate\Contracts\Cache\Repository;
use Ramon\Verified\TierConfig;

/**
 * Os tiers que vão no payload do fórum. O parse sanitiza o SVG de cada tier
 * com DOMDocument; sem cache, isso rodaria em toda carga de página. A chave é
 * o hash do JSON salvo, então editar os tiers já invalida o cache.
 */
class ForumTiers
{
    public function __construct(
        protected Repository $cache
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return TierConfig::parseForFrontend($raw);
        }

        return $this->cache->rememberForever(
            'ramon-verified.forum-tiers.v1.'.hash('xxh128', $raw),
            fn () => TierConfig::parseForFrontend($raw)
        );
    }
}
