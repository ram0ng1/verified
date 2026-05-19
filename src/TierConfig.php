<?php

namespace Ramon\Verified;

use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Ramon\Verified\Api\Controller\UploadBadgeSvgController;

/**
 * Configuração multi-tier do badge. A lista vive como JSON em
 * `ramon-verified.tiers`; esta classe é a fonte única de parsing e
 * validação tanto server-side quanto no `serializeToForum`.
 *
 * Shape de cada tier:
 * - id              slug [a-z0-9_-], 1..32 chars
 * - label           nome exibido, 1..64
 * - color           hex (#rgb / #rrggbb / #rrggbbaa); vazio cai no primary do forum
 * - description     texto do popover, até 280 chars; aceita `<strong>` e `<em>`
 * - learnMoreUrl    link opcional "Saiba mais"; apenas http/https
 * - autoGroups      IDs de grupos cujos membros recebem o tier automaticamente
 * - badgeEnabled    opt-in para usar um SVG customizado APENAS para este tier
 * - badgeSvg        SVG sanitizado, até `BADGE_SVG_MAX` bytes; ignorado se `badgeEnabled=false`
 */
class TierConfig
{
    public const DEFAULT_TIER_ID = 'blue';

    /**
     * Cap por tier para o SVG customizado. Cada tier extra é embarcado no
     * payload do forum (toda página), então um cap apertado evita bloat —
     * 8 tiers × 8 KB já são 64 KB serializados em cada carga, suficiente.
     */
    public const BADGE_SVG_MAX = 8 * 1024;

    /**
     * Cache por hash do input em escopo de processo. `parse` é chamada a cada
     * `serializeToForum` (toda montagem do payload) — sem cache, o sanitizer
     * SVG roda DOMDocument por tier, em cada page-load.
     *
     * @var array<string, array<int, array>>
     */
    private static array $parseCache = [];

    /**
     * Parse de um valor cru (JSON string ou array já decodificado) para uma
     * lista normalizada. Entradas malformadas são descartadas silenciosamente
     * para que uma linha ruim no JSON do admin nunca derrube o forum.
     *
     * @return array<int, array{id:string,label:string,color:string,description:string,learnMoreUrl:string,autoGroups:int[],badgeEnabled:bool,badgeSvg:string}>
     */
    public static function parse($raw): array
    {
        $cacheKey = is_string($raw)
            ? 's:'.crc32($raw)
            : (is_array($raw) ? 'a:'.crc32(serialize($raw)) : null);

        if ($cacheKey !== null && isset(self::$parseCache[$cacheKey])) {
            return self::$parseCache[$cacheKey];
        }

        $result = self::doParse($raw);

        if ($cacheKey !== null) {
            self::$parseCache[$cacheKey] = $result;
        }

        return $result;
    }

    private static function doParse($raw): array
    {
        $list = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (! is_array($list)) return [];

        $clean = [];
        $seen  = [];

        foreach ($list as $row) {
            if (! is_array($row)) continue;

            $id = isset($row['id']) ? strtolower(trim((string) $row['id'])) : '';
            if ($id === '' || ! preg_match('/^[a-z0-9_-]{1,32}$/', $id)) continue;
            if (isset($seen[$id])) continue;
            $seen[$id] = true;

            $label = isset($row['label']) ? trim((string) $row['label']) : '';
            if ($label === '') continue;

            $color = '';
            if (isset($row['color']) && is_string($row['color'])) {
                $c = trim($row['color']);
                if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $c)) {
                    $color = $c;
                }
            }

            $description = isset($row['description']) && is_string($row['description'])
                ? mb_substr(self::sanitiseDescription($row['description']), 0, 320)
                : '';

            $learnMoreUrl = '';
            if (isset($row['learnMoreUrl']) && is_string($row['learnMoreUrl'])) {
                $u = trim($row['learnMoreUrl']);
                if ($u !== '' && preg_match('#^https?://#i', $u)) {
                    $learnMoreUrl = mb_substr($u, 0, 500);
                }
            }

            $autoGroups = [];
            if (isset($row['autoGroups']) && is_array($row['autoGroups'])) {
                foreach ($row['autoGroups'] as $gid) {
                    $g = (int) $gid;
                    if ($g > 0) $autoGroups[] = $g;
                }
                $autoGroups = array_values(array_unique($autoGroups));
            }

            $badgeEnabled = ! empty($row['badgeEnabled']);
            $badgeSvg = '';
            if ($badgeEnabled && isset($row['badgeSvg']) && is_string($row['badgeSvg'])) {
                $candidate = $row['badgeSvg'];
                if ($candidate !== '' && strlen($candidate) <= self::BADGE_SVG_MAX) {
                    $sanitised = UploadBadgeSvgController::sanitizeSvg($candidate, false);
                    if ($sanitised !== '' && strlen($sanitised) <= self::BADGE_SVG_MAX) {
                        $badgeSvg = $sanitised;
                    }
                }
            }
            if ($badgeSvg === '') {
                $badgeEnabled = false;
            }

            $clean[] = [
                'id'           => mb_substr($id, 0, 32),
                'label'        => mb_substr($label, 0, 64),
                'color'        => $color,
                'description'  => $description,
                'learnMoreUrl' => $learnMoreUrl,
                'autoGroups'   => $autoGroups,
                'badgeEnabled' => $badgeEnabled,
                'badgeSvg'     => $badgeSvg,
            ];
        }

        return $clean;
    }

    public static function parseForFrontend($raw): array
    {
        return self::parse($raw);
    }

    public static function fromSettings(SettingsRepositoryInterface $settings): array
    {
        return self::parse($settings->get('ramon-verified.tiers'));
    }

    /**
     * Permite apenas `<strong>` e `<em>` no description — o restante é
     * escapado para neutralizar XSS no `m.trust()` do frontend.
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
     * Encontra o primeiro tier cujo `autoGroups` intersecta os grupos do
     * usuário. A ordem da lista é a precedência — admin controla via
     * reordenação.
     *
     * Administradores são imunes a auto-grant por membership implícita: um
     * tier mirando "Members" (ou qualquer grupo que o admin também pertença)
     * não verifica o admin. Admin só recebe auto-grant quando o tier lista
     * explicitamente o grupo Admin (id 1).
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
