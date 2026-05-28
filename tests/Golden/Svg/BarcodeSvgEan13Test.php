<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden\Svg;

use DragonOfMercy\PhpPdf\Barcode\Ean13;
use DragonOfMercy\PhpPdf\Barcode\Svg\SvgBarcodeRenderer;
use PHPUnit\Framework\TestCase;

final class BarcodeSvgEan13Test extends TestCase
{
    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/../fixtures-svg/barcode-svg-ean13.svg');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildSvgBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php');
    }

    public static function buildSvgBytes(): string
    {
        return (new SvgBarcodeRenderer())->render(Ean13::of('978013110362'), 300, 120);
    }
}
