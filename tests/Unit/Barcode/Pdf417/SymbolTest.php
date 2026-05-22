<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Pdf417;

use DragonOfMercy\PhpPdf\Barcode\Pdf417\Symbol;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class SymbolTest extends TestCase
{
    public function testEcCodewordCountIsPowerOfTwo(): void
    {
        self::assertSame(2, Symbol::ecCodewordCount(0));
        self::assertSame(8, Symbol::ecCodewordCount(2));
        self::assertSame(512, Symbol::ecCodewordCount(8));
    }

    public function testRecommendedEcLevelGrowsWithData(): void
    {
        // ISO/IEC 15438 Annex E recommended levels by data codeword count.
        self::assertSame(2, Symbol::recommendedEcLevel(40));
        self::assertSame(3, Symbol::recommendedEcLevel(160));
        self::assertSame(4, Symbol::recommendedEcLevel(320));
        self::assertSame(5, Symbol::recommendedEcLevel(800));
    }

    public function testChosenGridIsValidAndFitsTotal(): void
    {
        $sym = Symbol::choose(dataCodewords: 6, ecLevel: 2, columnHint: null);
        self::assertGreaterThanOrEqual(1, $sym->columns);
        self::assertLessThanOrEqual(30, $sym->columns);
        self::assertGreaterThanOrEqual(3, $sym->rows);
        self::assertLessThanOrEqual(90, $sym->rows);
        self::assertSame(8, $sym->ecCodewords);
        // The grid must hold data + length descriptor + EC.
        self::assertGreaterThanOrEqual(6 + 8, $sym->rows * $sym->columns);
    }

    public function testColumnHintHonored(): void
    {
        $sym = Symbol::choose(dataCodewords: 20, ecLevel: 2, columnHint: 4);
        self::assertSame(4, $sym->columns);
    }

    public function testOverCapacityThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/PDF417 data too large/');
        Symbol::choose(dataCodewords: 900, ecLevel: 8, columnHint: 1);
    }
}
