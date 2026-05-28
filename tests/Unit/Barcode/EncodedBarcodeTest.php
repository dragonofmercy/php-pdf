<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\BarcodeKind;
use DragonOfMercy\PhpPdf\Barcode\EncodedBarcode;
use DragonOfMercy\PhpPdf\Barcode\HumanTextSegment;
use DragonOfMercy\PhpPdf\Barcode\Orientation;
use DragonOfMercy\PhpPdf\Barcode\TextAnchor;
use DragonOfMercy\PhpPdf\Color;
use PHPUnit\Framework\TestCase;

final class EncodedBarcodeTest extends TestCase
{
    public function test1DConstruction(): void
    {
        $modules = [true, false, true, true, false];
        $segments = [
            new HumanTextSegment('1', 2.5, 6.0, 1.5, TextAnchor::MIDDLE),
        ];
        $color = Color::rgb(0, 0, 0);
        $enc = new EncodedBarcode(
            kind: BarcodeKind::LINEAR_1D,
            modules: $modules,
            humanTextSegments: $segments,
            color: $color,
            orientation: Orientation::Horizontal,
        );
        self::assertSame(BarcodeKind::LINEAR_1D, $enc->kind);
        self::assertSame($modules, $enc->modules);
        self::assertSame($segments, $enc->humanTextSegments);
        self::assertSame($color, $enc->color);
        self::assertSame(Orientation::Horizontal, $enc->orientation);
    }

    public function test2DConstruction(): void
    {
        $matrix = [[true, false, true], [false, true, false], [true, true, true]];
        $enc = new EncodedBarcode(
            kind: BarcodeKind::MATRIX_2D,
            modules: $matrix,
            humanTextSegments: [],
            color: Color::rgb(0, 0, 0),
            orientation: Orientation::Horizontal,
        );
        self::assertSame(BarcodeKind::MATRIX_2D, $enc->kind);
        self::assertSame($matrix, $enc->modules);
        self::assertSame([], $enc->humanTextSegments);
    }

    public function testBearerBarDefaultsNull(): void
    {
        $enc = new EncodedBarcode(BarcodeKind::LINEAR_1D, [true], [], Color::rgb(0, 0, 0), Orientation::Horizontal);
        self::assertNull($enc->bearerBarModules);
    }
}
