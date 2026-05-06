<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Page;

use PhpPdf\Font;
use PhpPdf\Font\MetricsRegistry;
use PhpPdf\Page\CellRenderer;
use PhpPdf\Page\ContentStream;
use PHPUnit\Framework\TestCase;

final class CellRendererTest extends TestCase
{
    private function renderer(): CellRenderer
    {
        return new CellRenderer(
            stream: new ContentStream(842.0),
            metrics: new MetricsRegistry(),
        );
    }

    public function testWrapTextSingleLine(): void
    {
        $r = $this->renderer();
        $result = $r->wrapText('Hello', 1000.0, Font::helvetica(), 12.0);
        self::assertSame(['Hello'], $result->lines);
        self::assertCount(1, $result->widths);
        self::assertGreaterThan(0.0, $result->widths[0]);
        self::assertSame(0, $result->brokenWords);
        self::assertFalse($result->textOverflow);
    }

    public function testWrapTextSplitsOnExplicitNewlines(): void
    {
        $r = $this->renderer();
        $result = $r->wrapText("a\nb\nc", 1000.0, Font::helvetica(), 12.0);
        self::assertSame(['a', 'b', 'c'], $result->lines);
    }

    public function testWrapTextEmptyParagraph(): void
    {
        $r = $this->renderer();
        $result = $r->wrapText("\n", 1000.0, Font::helvetica(), 12.0);
        self::assertSame(['', ''], $result->lines);
    }

    public function testWrapTextWordsWrapToFitWidth(): void
    {
        $r = $this->renderer();
        // Helvetica 'Hello' ~ 27.34pt. innerW 35 => "Hello" fits, "Hello world" doesn't.
        $result = $r->wrapText('Hello world there', 35.0, Font::helvetica(), 12.0);
        self::assertGreaterThan(1, count($result->lines));
        foreach ($result->lines as $line) {
            // Each line, isolated, fits within innerW.
            $width = (new MetricsRegistry())->metricsFor(Font::helvetica())->stringWidth($line, 12.0);
            self::assertLessThanOrEqual(35.0 + 0.001, $width);
        }
    }

    public function testWrapTextForceBreaksLongWord(): void
    {
        $r = $this->renderer();
        // A long word that doesn't fit in 30pt with Helvetica 12.
        $longWord = str_repeat('x', 30);
        $result = $r->wrapText($longWord, 30.0, Font::helvetica(), 12.0);
        self::assertGreaterThan(1, count($result->lines));
        self::assertSame(1, $result->brokenWords);
    }

    public function testWrapTextEncodesAccentsAsWinAnsiBytes(): void
    {
        $r = $this->renderer();
        $result = $r->wrapText('cafe', 1000.0, Font::helvetica(), 12.0);
        // Sanity check ASCII-only round-trip
        self::assertSame('cafe', $result->lines[0]);

        // Now with accented char: 'e' with acute is U+00E9 -> 0xE9 in WinAnsi
        $result2 = $r->wrapText("caf\xC3\xA9", 1000.0, Font::helvetica(), 12.0);
        self::assertSame("caf\xE9", $result2->lines[0]);
    }

    public function testWrapTextEmptyStringSpecialCase(): void
    {
        $r = $this->renderer();
        // Spec: empty text is short-circuited at the render() level (Task 13). wrapText
        // still returns a deterministic result for empty strings.
        $result = $r->wrapText('', 1000.0, Font::helvetica(), 12.0);
        self::assertSame([''], $result->lines);
        self::assertSame([0.0], $result->widths);
        self::assertSame(0, $result->brokenWords);
    }
}
