<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Page;

use PhpPdf\CellResult;
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

    public function testCondenseTextNoCompressionWhenFits(): void
    {
        $r = $this->renderer();
        // Helvetica "Hi" at 12pt is well below 1000pt -- scale stays 100.
        $result = $r->condenseText('Hi', 1000.0, Font::helvetica(), 12.0);
        self::assertSame(['Hi'], $result->lines);
        self::assertSame([100.0], $result->scales);
    }

    public function testCondenseTextScalesDownWhenOverflows(): void
    {
        $r = $this->renderer();
        // Force overflow: paraWidth is roughly Helvetica "Hello" ~ 27.34pt at 12pt.
        // innerW = 13.67 -> scale ~ 50.
        $result = $r->condenseText('Hello', 13.67, Font::helvetica(), 12.0);
        self::assertSame(['Hello'], $result->lines);
        self::assertCount(1, $result->scales);
        self::assertLessThan(100.0, $result->scales[0]);
        self::assertGreaterThan(0.0, $result->scales[0]);
    }

    public function testCondenseTextSplitsOnNewlines(): void
    {
        $r = $this->renderer();
        $result = $r->condenseText("a\nbb", 1000.0, Font::courier(), 10.0);
        self::assertSame(['a', 'bb'], $result->lines);
        self::assertSame([100.0, 100.0], $result->scales);
    }

    public function testCondenseTextEmptyParagraphScaleIs100(): void
    {
        $r = $this->renderer();
        $result = $r->condenseText('', 100.0, Font::helvetica(), 12.0);
        self::assertSame([''], $result->lines);
        self::assertSame([100.0], $result->scales);
    }

    public function testShrinkTextNoShrinkWhenFits(): void
    {
        $r = $this->renderer();
        $result = $r->shrinkText('Hi', 1000.0, Font::helvetica(), 12.0);
        self::assertSame(12.0, $result->effectiveSize);
        self::assertFalse($result->textOverflow);
    }

    public function testShrinkTextReducesSizeProportionally(): void
    {
        $r = $this->renderer();
        // Hello at Helvetica 12 ~ 27.336. innerW=13.67 -> ratio ~0.5 -> effectiveSize ~6.0.
        $result = $r->shrinkText('Hello', 13.67, Font::helvetica(), 12.0);
        self::assertEqualsWithDelta(6.0, $result->effectiveSize, 0.05);
        self::assertCount(1, $result->lines);
        self::assertCount(1, $result->widths);
    }

    public function testShrinkTextRatioBasedOnLongestLine(): void
    {
        $r = $this->renderer();
        // Two paragraphs; longest determines ratio.
        $result = $r->shrinkText("a\nHello", 13.67, Font::helvetica(), 12.0);
        self::assertCount(2, $result->lines);
        self::assertEqualsWithDelta(6.0, $result->effectiveSize, 0.05);
    }

    public function testShrinkTextExtremeNarrowSetsOverflow(): void
    {
        $r = $this->renderer();
        // 0.1pt is way below the originalSize/100 minSize threshold.
        $result = $r->shrinkText('Hello', 0.1, Font::helvetica(), 12.0);
        self::assertTrue($result->textOverflow);
        self::assertEqualsWithDelta(0.12, $result->effectiveSize, 0.001);
    }

    /**
     * @param callable(CellRenderer, ContentStream, string): CellResult $configure
     * @return array{0: CellResult, 1: string}
     */
    private function renderAndStream(
        callable $configure,
        string $text = 'Hello',
        ?int $expectedLines = null,
    ): array {
        $stream = new ContentStream(842.0);
        $r = new CellRenderer(stream: $stream, metrics: new MetricsRegistry());
        $result = $configure($r, $stream, $text);
        if ($expectedLines !== null) {
            self::assertSame($expectedLines, $result->lineCount);
        }
        return [$result, $stream->bytes()];
    }

    public function testRenderEmitsBeginEndText(): void
    {
        [$res, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r): \PhpPdf\CellResult => $r->render(
                font: Font::helvetica(),
                size: 12.0,
                customLeading: null,
                x: 50.0,
                y: 50.0,
                w: 200.0,
                h: null,
                text: 'Hello',
                border: null,
                fill: null,
                textColor: null,
                align: \PhpPdf\TextAlign::LEFT,
                verticalAlign: \PhpPdf\VerticalAlign::TOP,
                fit: \PhpPdf\Fit::NONE,
                padding: 2.0,
                fontShortName: 'F1',
            ),
        );
        self::assertStringContainsString("BT\n", $bytes);
        self::assertStringContainsString("ET\n", $bytes);
        self::assertStringContainsString('/F1', $bytes);
        self::assertSame(1, $res->lineCount);
    }

    public function testRenderWrapsBlocksInQAndQ(): void
    {
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r): \PhpPdf\CellResult => $r->render(
                font: Font::helvetica(), size: 12.0, customLeading: null,
                x: 50.0, y: 50.0, w: 100.0, h: null, text: 'Hi',
                border: null, fill: null, textColor: null,
                align: \PhpPdf\TextAlign::LEFT, verticalAlign: \PhpPdf\VerticalAlign::TOP,
                fit: \PhpPdf\Fit::NONE, padding: 2.0, fontShortName: 'F1',
            ),
        );
        // q must come before Q; both must be present.
        $qPos = strpos($bytes, "q\n");
        $qEndPos = strrpos($bytes, "Q\n");
        self::assertNotFalse($qPos);
        self::assertNotFalse($qEndPos);
        self::assertLessThan($qEndPos, $qPos);
    }

    public function testRenderEmitsFillRectangleWhenFillSet(): void
    {
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r): \PhpPdf\CellResult => $r->render(
                font: Font::helvetica(), size: 12.0, customLeading: null,
                x: 10.0, y: 20.0, w: 100.0, h: 30.0, text: '',
                border: null, fill: \PhpPdf\Color::rgb(255, 0, 0),
                textColor: null,
                align: \PhpPdf\TextAlign::LEFT, verticalAlign: \PhpPdf\VerticalAlign::TOP,
                fit: \PhpPdf\Fit::NONE, padding: 2.0, fontShortName: 'F1',
            ),
        );
        self::assertStringContainsString('1 0 0 rg', $bytes);
        self::assertStringContainsString("re\n", $bytes);
        self::assertStringContainsString("f\n", $bytes);
    }

    public function testRenderEmitsBordersForActiveSidesOnly(): void
    {
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r): \PhpPdf\CellResult => $r->render(
                font: Font::helvetica(), size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 50.0, h: 20.0, text: '',
                border: \PhpPdf\Border::sides(top: true, bottom: true),
                fill: null, textColor: null,
                align: \PhpPdf\TextAlign::LEFT, verticalAlign: \PhpPdf\VerticalAlign::TOP,
                fit: \PhpPdf\Fit::NONE, padding: 2.0, fontShortName: 'F1',
            ),
        );
        // Two strokes, one for each active side.
        self::assertSame(2, substr_count($bytes, "S\n"));
    }

    public function testRenderSkipsBordersWhenBorderIsNull(): void
    {
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r): \PhpPdf\CellResult => $r->render(
                font: Font::helvetica(), size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 50.0, h: 20.0, text: 'Hello',
                border: null, fill: null, textColor: null,
                align: \PhpPdf\TextAlign::LEFT, verticalAlign: \PhpPdf\VerticalAlign::TOP,
                fit: \PhpPdf\Fit::NONE, padding: 2.0, fontShortName: 'F1',
            ),
        );
        self::assertSame(0, substr_count($bytes, "S\n"));
    }

    public function testRenderSkipsBordersWhenIsEmpty(): void
    {
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r): \PhpPdf\CellResult => $r->render(
                font: Font::helvetica(), size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 50.0, h: 20.0, text: '',
                border: \PhpPdf\Border::none(), fill: null, textColor: null,
                align: \PhpPdf\TextAlign::LEFT, verticalAlign: \PhpPdf\VerticalAlign::TOP,
                fit: \PhpPdf\Fit::NONE, padding: 2.0, fontShortName: 'F1',
            ),
        );
        self::assertSame(0, substr_count($bytes, "S\n"));
    }

    public function testRenderEmitsDashedPattern(): void
    {
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r): \PhpPdf\CellResult => $r->render(
                font: Font::helvetica(), size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 50.0, h: 20.0, text: '',
                border: \PhpPdf\Border::all()->withStyle(\PhpPdf\BorderStyle::DASHED),
                fill: null, textColor: null,
                align: \PhpPdf\TextAlign::LEFT, verticalAlign: \PhpPdf\VerticalAlign::TOP,
                fit: \PhpPdf\Fit::NONE, padding: 2.0, fontShortName: 'F1',
            ),
        );
        self::assertStringContainsString('[3 3] 0 d', $bytes);
    }

    public function testRenderEmitsDottedPatternProportionalToWidth(): void
    {
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r): \PhpPdf\CellResult => $r->render(
                font: Font::helvetica(), size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 50.0, h: 20.0, text: '',
                border: \PhpPdf\Border::all()->withStyle(\PhpPdf\BorderStyle::DOTTED)->withWidth(2.0),
                fill: null, textColor: null,
                align: \PhpPdf\TextAlign::LEFT, verticalAlign: \PhpPdf\VerticalAlign::TOP,
                fit: \PhpPdf\Fit::NONE, padding: 2.0, fontShortName: 'F1',
            ),
        );
        // DOTTED at width=2 -> [2 4] dash pattern.
        self::assertStringContainsString('[2 4] 0 d', $bytes);
    }

    public function testRenderEmitsTzForCondense(): void
    {
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r): \PhpPdf\CellResult => $r->render(
                font: Font::helvetica(), size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 13.67, h: 20.0, text: 'Hello',
                border: null, fill: null, textColor: null,
                align: \PhpPdf\TextAlign::LEFT, verticalAlign: \PhpPdf\VerticalAlign::TOP,
                fit: \PhpPdf\Fit::CONDENSE, padding: 0.0, fontShortName: 'F1',
            ),
        );
        self::assertStringContainsString(' Tz', $bytes);
    }

    public function testRenderShrinkUsesSmallerTfSize(): void
    {
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r): \PhpPdf\CellResult => $r->render(
                font: Font::helvetica(), size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 13.67, h: 20.0, text: 'Hello',
                border: null, fill: null, textColor: null,
                align: \PhpPdf\TextAlign::LEFT, verticalAlign: \PhpPdf\VerticalAlign::TOP,
                fit: \PhpPdf\Fit::SHRINK, padding: 0.0, fontShortName: 'F1',
            ),
        );
        // Tf size will be ~6, never the original 12.
        self::assertMatchesRegularExpression('#/F1 [0-9]+(\.[0-9]+)? Tf#', $bytes);
        self::assertStringNotContainsString('/F1 12 Tf', $bytes);
    }

    public function testRenderEmptyTextSkipsBeginText(): void
    {
        [$res, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r): \PhpPdf\CellResult => $r->render(
                font: Font::helvetica(), size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 50.0, h: 20.0, text: '',
                border: \PhpPdf\Border::all(), fill: \PhpPdf\Color::rgb(255, 255, 255),
                textColor: null,
                align: \PhpPdf\TextAlign::LEFT, verticalAlign: \PhpPdf\VerticalAlign::TOP,
                fit: \PhpPdf\Fit::NONE, padding: 2.0, fontShortName: 'F1',
            ),
        );
        self::assertStringNotContainsString("BT\n", $bytes);
        self::assertSame(0, $res->lineCount);
    }

    public function testRenderResultGeometry(): void
    {
        [$res] = $this->renderAndStream(
            static fn (CellRenderer $r): \PhpPdf\CellResult => $r->render(
                font: Font::helvetica(), size: 12.0, customLeading: null,
                x: 50.0, y: 50.0, w: 200.0, h: 25.0, text: 'Hello',
                border: null, fill: null, textColor: null,
                align: \PhpPdf\TextAlign::LEFT, verticalAlign: \PhpPdf\VerticalAlign::TOP,
                fit: \PhpPdf\Fit::NONE, padding: 2.0, fontShortName: 'F1',
            ),
        );
        self::assertSame(250.0, $res->x);
        self::assertSame(75.0, $res->y);
        self::assertSame(25.0, $res->height);
    }

    public function testRenderHeightActsAsMinHeight(): void
    {
        [$res] = $this->renderAndStream(
            static fn (CellRenderer $r): \PhpPdf\CellResult => $r->render(
                font: Font::helvetica(), size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 50.0, h: 5.0, text: "Hello\nworld\nhere",
                border: null, fill: null, textColor: null,
                align: \PhpPdf\TextAlign::LEFT, verticalAlign: \PhpPdf\VerticalAlign::TOP,
                fit: \PhpPdf\Fit::NONE, padding: 0.0, fontShortName: 'F1',
            ),
        );
        // 3 lines of leading 14.4 each = 43.2, far exceeds h=5. Cell grows.
        self::assertGreaterThan(5.0, $res->height);
    }
}
