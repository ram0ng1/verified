<?php

namespace Ramon\Verified;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Multi-tier badge config.
 *
 * Tiers live as JSON in the `ramon-verified.tiers` setting. This class is the
 * single source of truth for parsing / validating that JSON, both server-side
 * (used by `UserResourceFields`, `VerifyUserController`, etc.) and when
 * serialising to the forum payload for the frontend.
 *
 * Each tier shape:
 *   - id            : slug, [a-z0-9_-], 1..32 chars
 *   - label         : display name, 1..64 chars
 *   - color         : hex (#rgb / #rrggbb / #rrggbbaa) — falls back to forum primary on the front
 *   - description   : popover body text, up to 280 chars
 *   - learnMoreUrl  : optional link shown as "Saiba mais"; only http/https allowed
 *   - autoGroups    : list of int group IDs whose members get this tier automatically
 */
class TierConfig
{
    /** Default tier ID assigned to legacy verified users (set by migration). */
    public const DEFAULT_TIER_ID = 'blue';

    /**
     * Parse a raw setting value (JSON string or already-decoded array) into a
     * normalised list of tiers. Drops malformed entries silently so a single
     * bad row in the admin's JSON can never crash the forum.
     *
     * @return array<int, array{id:string,label:string,color:string,description:string,learnMoreUrl:string,autoGroups:int[]}>
     */
    public static function parse($raw): array
    {
        $list = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (! is_array($list)) return [];

        $clean = [];
        $seen  = [];

        foreach ($list as $row) {
            if (! is_array($row)) continue;

            $id = isset($row['id']) ? strtolower(trim((string) $row['id'])) : '';
            // slug-style; stops a malicious admin from smuggling weird ids.
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

            $clean[] = [
                'id'           => mb_substr($id, 0, 32),
                'label'        => mb_substr($label, 0, 64),
                'color'        => $color,
                'description'  => $description,
                'learnMoreUrl' => $learnMoreUrl,
                'autoGroups'   => $autoGroups,
            ];
        }

        return $clean;
    }

    /**
     * Used by `serializeToForum` — exposes the parsed tier list under the
     * `ramonVerifiedTiers` forum attribute.
     */
    public static function parseForFrontend($raw): array
    {
        return self::parse($raw);
    }

    /**
     * Read tiers straight from the settings repository (server-side hot path).
     */
    public static function fromSettings(SettingsRepositoryInterface $settings): array
    {
        return self::parse($settings->get('ramon-verified.tiers'));
    }

    /**
     * Allow only `<strong>` and `<em>` tags inside the description so admins
     * can highlight key words with the same colored-bold treatment the
     * pre-tiers headline had ("Esta conta tem a <strong>identidade
     * verificada</strong>."). Everything else is escaped — the description
     * goes through `m.trust()` on the frontend, so anything not stripped here
     * could become an XSS vector.
     */
    protected static function sanitiseDescription(string $raw): string
    {
        $text = trim($raw);
        if ($text === '') return '';

        // Escape every special char first, then unescape the whitelisted tags.
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return preg_replace(
            ['#&lt;(/?)(strong|em)&gt;#i'],
            ['<$1$2>'],
            $escaped
        );
    }

    /**
     * Look up a tier by id. Returns null when the id is not configured.
     */
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
     * For a list of group IDs the user belongs to, find the first tier whose
     * `autoGroups` intersects. Order in the configured list is the priority —
     * admins control precedence by reordering tiers.
     *
     * @param int[] $userGroupIds
     */
    public static function autoTierFor(array $tiers, array $userGroupIds): ?array
    {
        if (empty($userGroupIds)) return null;
        foreach ($tiers as $tier) {
            if (! empty(array_intersect($tier['autoGroups'], $userGroupIds))) {
                return $tier;
            }
        }
        return null;
    }
}
