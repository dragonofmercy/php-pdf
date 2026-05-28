<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden\Svg;

use DragonOfMercy\PhpPdf\Barcode\AztecCode;
use DragonOfMercy\PhpPdf\Barcode\Svg\SvgBarcodeRenderer;
use PHPUnit\Framework\TestCase;

final class BarcodeSvgAztecTest extends TestCase
{
    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/../fixtures-svg/barcode-svg-aztec.svg');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildSvgBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php');
    }

    public static function buildSvgBytes(): string
    {
        return (new SvgBarcodeRenderer())->render(AztecCode::of('AZTEC TEST'), 200, 200);
    }
}
