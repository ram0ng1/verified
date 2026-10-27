<?php

declare(strict_types=1);

namespace Ramon\Verified\Tests\unit;

use PHPUnit\Framework\TestCase;
use Ramon\Verified\TierConfig;

/**
 * A descrição do tier sai do servidor já escapada (allowlist `<strong>`/`<em>`).
 */
final class TierDescriptionTest extends TestCase
{
    private function describe(string $description): string
    {
        $tiers = TierConfig::parseForFrontend(json_encode([
            ['id' => 'blue', 'label' => 'Azul', 'color' => '#1d9bf0', 'description' => $description],
        ]));

        return $tiers[0]['description'];
    }

    public function test_escapa_uma_vez_e_mantem_strong(): void
    {
        self::assertSame('A &amp; B <strong>verificada</strong>', $this->describe('A & B <strong>verificada</strong>'));
    }

    public function test_html_fora_da_allowlist_vira_texto(): void
    {
        self::assertSame('&lt;img src=x&gt;', $this->describe('<img src=x>'));
    }

    /** Cortar depois de escapar partia a entidade ao meio (`&am`). */
    public function test_corte_de_320_nao_parte_entidade(): void
    {
        $out = $this->describe(str_repeat('a', 318).'&&&&');

        self::assertMatchesRegularExpression('/&amp;&amp;$/', $out);
        self::assertDoesNotMatchRegularExpression('/&[a-z]*$/', preg_replace('/&amp;/', '', $out) ?? '');
    }
}
