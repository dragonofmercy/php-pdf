<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Pdf417;

use DragonOfMercy\PhpPdf\Barcode\Pdf417\CodewordTable;
use PHPUnit\Framework\TestCase;

final class CodewordTableTest extends TestCase
{
    public function testStartAndStopPatterns(): void
    {
        self::assertSame(0x1FEA8, CodewordTable::START_PATTERN);
        self::assertSame(0x3FA29, CodewordTable::STOP_PATTERN);
    }

    public function testHasThreeClustersOf929(): void
    {
        for ($cluster = 0; $cluster < 3; $cluster++) {
            self::assertCount(929, CodewordTable::patterns($cluster));
        }
    }

    public function testKnownBoundaryPatterns(): void
    {
        // Verbatim against the zxing CODEWORD_TABLE source.
        self::assertSame(0x1D5C0, CodewordTable::patterns(0)[0]);
        self::assertSame(0x1BEF4, CodewordTable::patterns(0)[928]);
        self::assertSame(0x1F560, CodewordTable::patterns(1)[0]);
        self::assertSame(0x1ABE0, CodewordTable::patterns(2)[0]);
        self::assertSame(0x1C7EA, CodewordTable::patterns(2)[928]);
    }

    public function testEveryPatternFitsSeventeenBits(): void
    {
        for ($cluster = 0; $cluster < 3; $cluster++) {
            foreach (CodewordTable::patterns($cluster) as $cw => $pattern) {
                self::assertGreaterThan(0, $pattern, "cluster {$cluster} cw {$cw}");
                self::assertLessThan(1 << 17, $pattern, "cluster {$cluster} cw {$cw} fits 17 bits");
            }
        }
    }
}
