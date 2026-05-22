<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Pdf417;

use DragonOfMercy\PhpPdf\Barcode\Pdf417\Encoder;
use DragonOfMercy\PhpPdf\Barcode\Pdf417\Matrix;
use PHPUnit\Framework\TestCase;

final class MatrixTest extends TestCase
{
    public function testRowCountMatchesSymbol(): void
    {
        $result = Encoder::encode('PDF417 sample', ecLevel: 2, columnHint: 2);
        $rows = Matrix::build($result);
        self::assertCount($result->rows, $rows);
    }

    public function testModulesPerRow(): void
    {
        // start(17) + leftIndicator(17) + columns*17 + rightIndicator(17) + stop(18).
        self::assertSame(17 * (2 + 3) + 18, Matrix::modulesPerRow(2));

        // The single source must match what build() actually produces per row.
        $result = Encoder::encode('PDF417 sample', ecLevel: 2, columnHint: 2);
        $rows = Matrix::build($result);
        $expected = Matrix::modulesPerRow($result->columns);
        foreach ($rows as $i => $row) {
            self::assertCount($expected, $row, "row {$i} module count");
        }
    }

    public function testRowStartsWithStartPattern(): void
    {
        $result = Encoder::encode('hi', ecLevel: 0, columnHint: 1);
        $rows = Matrix::build($result);
        $start = array_map(static fn (bool $b): int => $b ? 1 : 0, array_slice($rows[0], 0, 17));
        // START_PATTERN 0x1FEA8 MSB-first over 17 modules.
        self::assertSame([1,1,1,1,1,1,1,1,0,1,0,1,0,1,0,0,0], $start);
    }

    public function testRowEndsWithStopPattern(): void
    {
        $result = Encoder::encode('hi', ecLevel: 0, columnHint: 1);
        $rows = Matrix::build($result);
        $stop = array_map(static fn (bool $b): int => $b ? 1 : 0, array_slice($rows[0], -18));
        // STOP_PATTERN 0x3FA29 MSB-first over 18 modules.
        $expected = [];
        for ($i = 17; $i >= 0; $i--) {
            $expected[] = ((0x3FA29 >> $i) & 1) === 1 ? 1 : 0;
        }
        self::assertSame($expected, $stop);
    }
}
