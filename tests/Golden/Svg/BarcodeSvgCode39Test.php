<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden\Svg;

use DragonOfMercy\PhpPdf\Barcode\Code39;
use DragonOfMercy\PhpPdf\Barcode\Svg\SvgBarcodeRenderer;
use PHPUnit\Framework\TestCase;

final class BarcodeSvgCode39Test extends TestCase
{
    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/../fixtures-svg/barcode-svg-code39.svg');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildSvgBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php');
    }

    public static function buildSvgBytes(): string
    {
        return (new SvgBarcodeRenderer())->render(Code39::of('CODE 39'), 300, 100);
    }
}
