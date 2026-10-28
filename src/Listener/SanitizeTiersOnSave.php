<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Listener;

use Flarum\Settings\Event\Saving;
use Ramon\Verified\Support\SvgSanitizer;
use Ramon\Verified\TierConfig;

/**
 * Sanitiza os SVGs dos tiers na escrita, para que nenhum consumidor da setting
 * crua veja SVG malicioso. Os demais campos já são validados por
 * `TierConfig::doParse` na leitura.
 */
class SanitizeTiersOnSave
{
    public function handle(Saving $event): void
    {
        // O SVG global também pode chegar direto por `POST /api/settings`,
        // sem passar pelo `UploadBadgeSvgController` que o sanitiza.
        if (isset($event->settings['ramon-verified.badge_svg_content'])) {
            $svg = $event->settings['ramon-verified.badge_svg_content'];
            $event->settings['ramon-verified.badge_svg_content'] = is_string($svg) && $svg !== ''
                ? SvgSanitizer::sanitize($svg, false)
                : '';
        }

        if (! isset($event->settings['ramon-verified.tiers'])) {
            return;
        }

        $raw = $event->settings['ramon-verified.tiers'];
        if (! is_string($raw) || $raw === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return;
        }

        $clean = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) continue;

            if (isset($row['badgeSvg']) && is_string($row['badgeSvg']) && $row['badgeSvg'] !== '') {
                if (strlen($row['badgeSvg']) > TierConfig::BADGE_SVG_MAX) {
                    $row['badgeSvg'] = '';
                    $row['badgeEnabled'] = false;
                } else {
                    $sanitised = SvgSanitizer::sanitize($row['badgeSvg'], false);
                    if ($sanitised === '' || strlen($sanitised) > TierConfig::BADGE_SVG_MAX) {
                        $row['badgeSvg'] = '';
                        $row['badgeEnabled'] = false;
                    } else {
                        $row['badgeSvg'] = $sanitised;
                    }
                }
            }

            $clean[] = $row;
        }

        $event->settings['ramon-verified.tiers'] = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
