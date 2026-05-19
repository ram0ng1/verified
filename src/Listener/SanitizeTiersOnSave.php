<?php

namespace Ramon\Verified\Listener;

use Flarum\Settings\Event\Saving;
use Ramon\Verified\Support\SvgSanitizer;
use Ramon\Verified\TierConfig;

/**
 * Sanitiza `ramon-verified.tiers` no caminho de write. Sem
 * isso, o JSON enviado pelo admin (que contém SVG bruto carregado pelo
 * file picker) vai direto ao banco; só seria saneado quando lido por
 * `TierConfig::parse`. Defesa-em-profundidade: garante que NENHUM consumer
 * que ler a setting raw consiga ver SVG malicioso.
 *
 * Apenas o campo `badgeSvg` de cada tier passa pelo sanitizer — os demais
 * (id/label/color/description/learnMoreUrl/autoGroups) já são validados
 * por `TierConfig::doParse` no read.
 */
class SanitizeTiersOnSave
{
    public function handle(Saving $event): void
    {
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
