<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Pdf417;

use DragonOfMercy\PhpPdf\Barcode\Pdf417\Encoder;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class EncoderTest extends TestCase
{
    public function testGridSizeEqualsRowsTimesColumns(): void
    {
        $r = Encoder::encode('PDF417 sample', ecLevel: null, columnHint: null);
        self::assertCount($r->rows * $r->columns, $r->codewords);
    }

    public function testFirstCodewordIsDataRegionLength(): void
    {
        $r = Encoder::encode('PDF417 sample', ecLevel: null, columnHint: null);
        $dataRegion = $r->rows * $r->columns - $r->ecCodewordCount();
        self::assertSame($dataRegion, $r->codewords[0]);
    }

    public function testExplicitEcLevelHonored(): void
    {
        $r = Encoder::encode('hi there', ecLevel: 5, columnHint: null);
        self::assertSame(5, $r->ecLevel);
    }

    public function testColumnHintHonored(): void
    {
        $r = Encoder::encode('Tracking PKG-2026', ecLevel: 2, columnHint: 4);
        self::assertSame(4, $r->columns);
    }

    public function testCodewordsInValidRange(): void
    {
        $r = Encoder::encode('PDF417 sample 12345', ecLevel: null, columnHint: null);
        foreach ($r->codewords as $c) {
            self::assertGreaterThanOrEqual(0, $c);
            self::assertLessThan(929, $c);
        }
    }

    public function testEmptyThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/PDF417 data must not be empty/');
        Encoder::encode('', ecLevel: null, columnHint: null);
    }
}
