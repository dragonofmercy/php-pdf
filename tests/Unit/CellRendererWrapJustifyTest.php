<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Font\StandardFontEngine;
use DragonOfMercy\PhpPdf\Page\CellRenderer;
use DragonOfMercy\PhpPdf\Page\ContentStream;
use PHPUnit\Framework\TestCase;

final class CellRendererWrapJustifyTest extends TestCase
{
    private function engine(): StandardFontEngine
    {
        $font = Font::helvetica();
        return new StandardFontEngine($font, (new MetricsRegistry())->metricsFor($font));
    }

    public function testLastLineOfParagraphNotJustifiable(): void
    {
        $renderer = new CellRenderer(new ContentStream(842.0));
        // narrow width forces a wrap into >=2 lines
        $wrap = $renderer->wrapText('one two three four five six seven eight', 60.0, $this->engine(), 12.0);
        self::assertGreaterThan(1, count($wrap->lines));
        self::assertSame(count($wrap->lines), count($wrap->justify));
        // every line justifiable except the final one
        $expected = array_fill(0, count($wrap->lines), true);
        $expected[count($wrap->lines) - 1] = false;
        self::assertSame($expected, $wrap->justify);
    }

    public function testEachParagraphLastLineNotJustifiable(): void
    {
        $renderer = new CellRenderer(new ContentStream(842.0));
        // two single-line paragraphs (wide width => no wrap) => both are paragraph-final => both false
        $wrap = $renderer->wrapText("alpha\nbeta", 500.0, $this->engine(), 12.0);
        self::assertSame([false, false], $wrap->justify);
    }

    public function testEmptyParagraphLineNotJustifiable(): void
    {
        $renderer = new CellRenderer(new ContentStream(842.0));
        // blank line between two paragraphs
        $wrap = $renderer->wrapText("alpha\n\nbeta", 500.0, $this->engine(), 12.0);
        self::assertSame([false, false, false], $wrap->justify);
    }
}
