<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden\Svg;

use DragonOfMercy\PhpPdf\Barcode\DataMatrix;
use DragonOfMercy\PhpPdf\Barcode\Svg\SvgBarcodeRenderer;
use PHPUnit\Framework\TestCase;

final class BarcodeSvgDataMatrixTest extends TestCase
{
    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/../fixtures-svg/barcode-svg-datamatrix.svg');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildSvgBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php');
    }

    public static function buildSvgBytes(): string
    {
        return (new SvgBarcodeRenderer())->render(DataMatrix::of('DATAMATRIX TEST'), 200, 200);
    }
}
