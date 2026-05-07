<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\Renderer;
use DragonOfMercy\PhpPdf\Color;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    public function testRunLengthRowAggregatesAdjacentTrueModules(): void
    {
        // Pattern: 1 1 0 1 1 1 0 -> two runs: [0..2)=2 modules, [3..6)=3 modules.
        $row = [true, true, false, true, true, true, false];
        $ops = Renderer::runLengthRow($row, xStart: 10.0, y: 20.0, moduleWidth: 0.5, h: 5.0);
        // Expect two `re` ops:
        //   "10 20 1 5 re\n"   (run of 2 modules, width 1.0)
        //   "11.5 20 1.5 5 re\n" (run of 3 modules, width 1.5, starting at 10 + 3*0.5)
        self::assertStringContainsString('10 20 1 5 re', $ops);
        self::assertStringContainsString('11.5 20 1.5 5 re', $ops);
        self::assertSame(2, substr_count($ops, ' re'));
    }

    public function testRunLengthRowEmptyWhenAllFalse(): void
    {
        $row = [false, false, false];
        $ops = Renderer::runLengthRow($row, xStart: 0.0, y: 0.0, moduleWidth: 1.0, h: 1.0);
        self::assertSame('', $ops);
    }

    public function testRunLengthMatrixWalksRowsTopDownButYAxisGoesUp(): void
    {
        // 3x3 matrix, all true on first row only.
        $matrix = [
            [true, true, true],
            [false, false, false],
            [false, false, false],
        ];
        $ops = Renderer::runLengthMatrix($matrix, xStart: 0.0, yTopDown: 0.0, moduleSize: 1.0);
        // First row at top-down y=0 occupies pixels [0..1] in user space.
        // Page::barcode draws into the Y-down user space; runner emits one rect.
        self::assertSame(1, substr_count($ops, ' re'));
        self::assertStringContainsString('0 0 3 1 re', $ops);
    }

    public function testWrapEmitsQColorThenBodyThenFThenQ(): void
    {
        $body = "1 2 3 4 re\n5 6 7 8 re\n";
        $wrapped = Renderer::wrap($body, Color::rgb(0, 0, 0));
        self::assertStringStartsWith("q\n", $wrapped);
        self::assertStringContainsString("0 0 0 rg\n", $wrapped);
        self::assertStringContainsString($body, $wrapped);
        self::assertStringContainsString("f\n", $wrapped);
        self::assertStringEndsWith("Q\n", $wrapped);
        // Single fill -- there must be exactly one " f\n" or "f\n".
        self::assertSame(1, substr_count($wrapped, "f\n"));
    }
}
