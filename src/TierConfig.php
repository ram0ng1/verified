<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified;

use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Ramon\Verified\Support\SvgSanitizer;

/**
 * Fonte única de parsing e validação dos tiers (`ramon-verified.tiers`).
 *
 * Shape de cada tier:
 * - id              slug [a-z0-9_-], 1..32 chars
 * - label           nome exibido, 1..64
 * - color           hex (#rgb / #rrggbb / #rrggbbaa); vazio cai no primary do fórum
 * - description     texto do popover, até 320 chars; aceita `<strong>` e `<em>`
 * - learnMoreUrl    link opcional "Saiba mais"; apenas http/https
 * - autoGroups      IDs de grupos cujos membros recebem o tier automaticamente
 * - badgeEnabled    usa um SVG customizado só neste tier
 * - badgeSvg        SVG sanitizado, até `BADGE_SVG_MAX` bytes; ignorado se `badgeEnabled=false`
 */
class TierConfig
{
    public const DEFAULT_TIER_ID = 'blue';

    /**
     * Todo SVG de tier vai no payload do fórum em cada página: 8 tiers × 8 KB já
     * são 64 KB por carga.
     */
    public const BADGE_SVG_MAX = 8 * 1024;

    /**
     * Sem cache, o sanitizer rodaria DOMDocument por tier a cada payload do fórum.
     * O LRU limitado importa em processos longos (filas, Octane, RoadRunner): sem
     * teto, cada save no admin deixaria uma entrada viva para sempre.
     *
     * @var array<string, array<int, array>>
     */
    private static array $parseCache = [];

    private const CACHE_LIMIT = 16;

    /**
     * Entradas malformadas são descartadas em silêncio, para uma linha ruim nunca
     * derrubar o fórum. A chave usa `xxh128`: com `crc32`, uma colisão acidental
     * devolveria a configuração errada.
     *
     * @return array<int, array{id:string,label:string,color:string,description:string,learnMoreUrl:string,autoGroups:int[],badgeEnabled:bool,badgeSvg:string}>
     */
    public static function parse(mixed $raw): array
    {
        $cacheKey = is_string($raw)
            ? 's:'.hash('xxh128', $raw)
            : (is_array($raw) ? 'a:'.hash('xxh128', serialize($raw)) : null);

        if ($cacheKey !== null && isset(self::$parseCache[$cacheKey])) {
            // Move o hit para o fim, para as entradas frias saírem primeiro.
            $hit = self::$parseCache[$cacheKey];
            unset(self::$parseCache[$cacheKey]);
            self::$parseCache[$cacheKey] = $hit;
            return $hit;
        }

        $result = self::doParse($raw);

        if ($cacheKey !== null) {
            if (count(self::$parseCache) >= self::CACHE_LIMIT) {
                array_shift(self::$parseCache);
            }
            self::$parseCache[$cacheKey] = $result;
        }

        return $result;
    }

    private static function doParse(mixed $raw): array
    {
        $list = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (! is_array($list)) return [];

        $clean = [];
        $seen  = [];

        foreach ($list as $row) {
            $parsed = self::parseTierRow($row, $seen);
            if ($parsed !== null) {
                $clean[] = $parsed;
            }
        }

        return $clean;
    }

    /**
     * `null` para linha malformada: não-array, `id` inválido ou duplicado, `label` vazio.
     *
     * @param array<string, true> $seen
     * @return array{id:string,label:string,color:string,description:string,learnMoreUrl:string,autoGroups:int[],badgeEnabled:bool,badgeSvg:string}|null
     */
    private static function parseTierRow(mixed $row, array &$seen): ?array
    {
        if (! is_array($row)) return null;

        $id = isset($row['id']) ? strtolower(trim((string) $row['id'])) : '';
        if ($id === '' || ! preg_match('/^[a-z0-9_-]{1,32}$/', $id)) return null;
        if (isset($seen[$id])) return null;
        $seen[$id] = true;

        $label = isset($row['label']) ? trim((string) $row['label']) : '';
        if ($label === '') return null;

        [$badgeEnabled, $badgeSvg] = self::parseBadge($row);

        return [
            'id'           => mb_substr($id, 0, 32),
            'label'        => mb_substr($label, 0, 64),
            'color'        => self::parseColor($row),
            'description'  => self::parseDescription($row),
            'learnMoreUrl' => self::parseLearnMoreUrl($row),
            'autoGroups'   => self::parseAutoGroups($row),
            'badgeEnabled' => $badgeEnabled,
            'badgeSvg'     => $badgeSvg,
        ];
    }

    /**
     * Vazio quando ausente ou inválida; o frontend cai no `@primary-color`.
     */
    private static function parseColor(array $row): string
    {
        if (isset($row['color']) && is_string($row['color'])) {
            $c = trim($row['color']);
            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $c)) {
                return $c;
            }
        }

        return '';
    }

    private static function parseDescription(array $row): string
    {
        return isset($row['description']) && is_string($row['description'])
            // Corta antes de escapar: depois, o corte podia partir uma
            // entidade ao meio (`&am`).
            ? self::sanitiseDescription(mb_substr(trim($row['description']), 0, 320))
            : '';
    }

    private static function parseLearnMoreUrl(array $row): string
    {
        if (isset($row['learnMoreUrl']) && is_string($row['learnMoreUrl'])) {
            $u = trim($row['learnMoreUrl']);
            if ($u !== '' && preg_match('#^https?://#i', $u)) {
                return mb_substr($u, 0, 500);
            }
        }

        return '';
    }

    /**
     * @return int[]
     */
    private static function parseAutoGroups(array $row): array
    {
        if (! isset($row['autoGroups']) || ! is_array($row['autoGroups'])) {
            return [];
        }

        $autoGroups = [];
        foreach ($row['autoGroups'] as $gid) {
            $g = (int) $gid;
            if ($g > 0) $autoGroups[] = $g;
        }

        return array_values(array_unique($autoGroups));
    }

    /**
     * `BADGE_SVG_MAX` vale antes e depois da sanitização; qualquer falha zera o par.
     *
     * @return array{0: bool, 1: string} `[badgeEnabled, badgeSvg]`
     */
    private static function parseBadge(array $row): array
    {
        $badgeEnabled = ! empty($row['badgeEnabled']);
        $badgeSvg = '';

        if ($badgeEnabled && isset($row['badgeSvg']) && is_string($row['badgeSvg'])) {
            $candidate = $row['badgeSvg'];
            if ($candidate !== '' && strlen($candidate) <= self::BADGE_SVG_MAX) {
                $sanitised = SvgSanitizer::sanitize($candidate, false);
                if ($sanitised !== '' && strlen($sanitised) <= self::BADGE_SVG_MAX) {
                    $badgeSvg = $sanitised;
                }
            }
        }

        if ($badgeSvg === '') {
            $badgeEnabled = false;
        }

        return [$badgeEnabled, $badgeSvg];
    }

    public static function parseForFrontend(mixed $raw): array
    {
        return self::parse($raw);
    }

    public static function fromSettings(SettingsRepositoryInterface $settings): array
    {
        return self::parse($settings->get('ramon-verified.tiers'));
    }

    /**
     * O frontend renderiza com `m.trust()`, então tudo além de `<strong>` e `<em>`
     * é escapado.
     */
    protected static function sanitiseDescription(string $raw): string
    {
        $text = trim($raw);
        if ($text === '') return '';

        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return preg_replace(
            ['#&lt;(/?)(strong|em)&gt;#i'],
            ['<$1$2>'],
            $escaped
        );
    }

    public static function findById(array $tiers, ?string $id): ?array
    {
        if ($id === null || $id === '') return null;
        $needle = strtolower($id);
        foreach ($tiers as $t) {
            if ($t['id'] === $needle) return $t;
        }
        return null;
    }

    /**
     * A ordem da lista é a precedência. Admins não recebem auto-grant por grupos
     * que também têm (como Members), só quando o tier lista o grupo Admin.
     *
     * @param int[] $userGroupIds
     */
    public static function autoTierFor(array $tiers, array $userGroupIds): ?array
    {
        if (empty($userGroupIds)) return null;

        $isAdmin = in_array(Group::ADMINISTRATOR_ID, $userGroupIds, true);

        foreach ($tiers as $tier) {
            if (empty(array_intersect($tier['autoGroups'], $userGroupIds))) continue;

            if ($isAdmin && ! in_array(Group::ADMINISTRATOR_ID, $tier['autoGroups'], true)) {
                continue;
            }

            return $tier;
        }
        return null;
    }
}
