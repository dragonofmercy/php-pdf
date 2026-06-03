<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Table;

use DragonOfMercy\PhpPdf\Table\Column;
use DragonOfMercy\PhpPdf\Table\TableRenderer;
use PHPUnit\Framework\TestCase;

final class TableWidthsTest extends TestCase
{
    public function testAllFixed(): void
    {
        $cols = [Column::of('a')->width(60.0), Column::of('b')->width(30.0)];
        self::assertSame([60.0, 30.0], TableRenderer::distributeWidths($cols, 90.0));
    }

    public function testFillSharesRemainder(): void
    {
        $cols = [Column::of('a')->fill(), Column::of('b')->width(20.0), Column::of('c')->width(30.0)];
        // total 170, fixed 50 -> name gets 120
        self::assertSame([120.0, 20.0, 30.0], TableRenderer::distributeWidths($cols, 170.0));
    }

    public function testFillWeights(): void
    {
        $cols = [Column::of('a')->fill(1), Column::of('b')->fill(3)];
        // total 80, no fixed -> 20 / 60
        self::assertSame([20.0, 60.0], TableRenderer::distributeWidths($cols, 80.0));
    }

    public function testFixedOverflowHonored(): void
    {
        $cols = [Column::of('a')->width(100.0), Column::of('b')->width(100.0)];
        // total smaller than the sum of fixed -> honor fixed (no shrink)
        self::assertSame([100.0, 100.0], TableRenderer::distributeWidths($cols, 150.0));
    }

    public function testFillFloorsAtZeroWhenFixedExceedsTotal(): void
    {
        $cols = [Column::of('a')->width(160.0), Column::of('b')->fill()];
        // fixed 160 >= total 150 -> remainder clamped to 0 for fill column
        self::assertSame([160.0, 0.0], TableRenderer::distributeWidths($cols, 150.0));
    }
}
