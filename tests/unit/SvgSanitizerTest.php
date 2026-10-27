<?php

declare(strict_types=1);

namespace Ramon\Verified\Tests\unit;

use PHPUnit\Framework\TestCase;
use Ramon\Verified\Support\SvgSanitizer;

/**
 * O SVG sanitizado vai para o DOM do fórum via innerHTML. Nada que o parser
 * HTML leia diferente do parser XML pode sobrar: comentários, CDATA, PIs e
 * referências externas em qualquer namespace.
 */
final class SvgSanitizerTest extends TestCase
{
    private const NS = 'xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"';

    public function test_remove_comentarios_que_viram_mxss_no_html(): void
    {
        $out = SvgSanitizer::sanitize('<svg '.self::NS.'><!--><img/src/onerror=alert(1)>--><path d="M0 0"/></svg>');

        self::assertStringNotContainsString('<!--', $out);
        self::assertStringNotContainsString('onerror', $out);
        self::assertStringContainsString('<path', $out);
    }

    public function test_remove_cdata(): void
    {
        $out = SvgSanitizer::sanitize('<svg '.self::NS.'><g><![CDATA[<img src=x onerror=alert(1)>]]></g></svg>');

        self::assertStringNotContainsString('CDATA', $out);
        self::assertStringNotContainsString('onerror', $out);
    }

    public function test_remove_processing_instruction(): void
    {
        $out = SvgSanitizer::sanitize('<svg '.self::NS.'><?x ><img/src/onerror=alert(1)>?><path d="M0 0"/></svg>');

        self::assertStringNotContainsString('<?x', $out);
        self::assertStringNotContainsString('onerror', $out);
    }

    public function test_remove_xlink_href_externo(): void
    {
        $out = SvgSanitizer::sanitize('<svg '.self::NS.'><use xlink:href="https://evil.example/x.svg#a"/></svg>');

        self::assertStringNotContainsString('evil.example', $out);
    }

    public function test_remove_xlink_href_data_e_javascript(): void
    {
        $out = SvgSanitizer::sanitize(
            '<svg '.self::NS.'><use xlink:href="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4="/>'
            .'<use xlink:href="javascript:alert(1)"/></svg>'
        );

        self::assertStringNotContainsString('data:', $out);
        self::assertStringNotContainsString('javascript:', $out);
    }

    public function test_mantem_referencia_interna(): void
    {
        $out = SvgSanitizer::sanitize('<svg '.self::NS.'><defs><path id="p" d="M0 0"/></defs><use xlink:href="#p"/></svg>');

        self::assertStringContainsString('#p', $out);
    }
}
