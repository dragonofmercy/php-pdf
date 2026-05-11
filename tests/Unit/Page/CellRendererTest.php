<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\CellPadding;
use DragonOfMercy\PhpPdf\CellResult;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\FontEngine;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Font\StandardFontEngine;
use DragonOfMercy\PhpPdf\Image\ImageRegistry;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\Page\CellRenderer;
use DragonOfMercy\PhpPdf\Page\ContentStream;
use PHPUnit\Framework\TestCase;

final class CellRendererTest extends TestCase
{
    private function renderer(): CellRenderer
    {
        return new CellRenderer(stream: new ContentStream(842.0));
    }

    private function engine(?Font $font = null): FontEngine
    {
        $font ??= Font::helvetica();
        return new StandardFontEngine($font, (new MetricsRegistry())->metricsFor($font));
    }

    private function page(): Page
    {
        return new Page(
            pageWidth: 595,
            pageHeight: 842,
            fontRegistry: new FontRegistry(),
            metricsRegistry: new MetricsRegistry(),
            imageRegistry: new ImageRegistry(),
        );
    }

    public function testWrapTextSingleLine(): void
    {
        $r = $this->renderer();
        $result = $r->wrapText('Hello', 1000.0, $this->engine(), 12.0);
        self::assertSame(['Hello'], $result->lines);
        self::assertCount(1, $result->widths);
        self::assertGreaterThan(0.0, $result->widths[0]);
        self::assertSame(0, $result->brokenWords);
        self::assertFalse($result->textOverflow);
    }

    public function testWrapTextSplitsOnExplicitNewlines(): void
    {
        $r = $this->renderer();
        $result = $r->wrapText("a\nb\nc", 1000.0, $this->engine(), 12.0);
        self::assertSame(['a', 'b', 'c'], $result->lines);
    }

    public function testWrapTextEmptyParagraph(): void
    {
        $r = $this->renderer();
        $result = $r->wrapText("\n", 1000.0, $this->engine(), 12.0);
        self::assertSame(['', ''], $result->lines);
    }

    public function testWrapTextWordsWrapToFitWidth(): void
    {
        $r = $this->renderer();
        $engine = $this->engine();
        // Helvetica 'Hello' ~ 27.34pt. innerW 35 => "Hello" fits, "Hello world" doesn't.
        $result = $r->wrapText('Hello world there', 35.0, $engine, 12.0);
        self::assertGreaterThan(1, count($result->lines));
        foreach ($result->lines as $line) {
            // Each line, isolated, fits within innerW.
            $width = $engine->measure($line, 12.0);
            self::assertLessThanOrEqual(35.0 + 0.001, $width);
        }
    }

    public function testWrapTextForceBreaksLongWord(): void
    {
        $r = $this->renderer();
        // A long word that doesn't fit in 30pt with Helvetica 12.
        $longWord = str_repeat('x', 30);
        $result = $r->wrapText($longWord, 30.0, $this->engine(), 12.0);
        self::assertGreaterThan(1, count($result->lines));
        self::assertSame(1, $result->brokenWords);
    }

    public function testWrapTextEncodesAccentsAsWinAnsiBytes(): void
    {
        $r = $this->renderer();
        $engine = $this->engine();
        $result = $r->wrapText('cafe', 1000.0, $engine, 12.0);
        // Sanity check ASCII-only round-trip
        self::assertSame('cafe', $result->lines[0]);

        // With the FontEngine seam, lines stay in raw UTF-8 (encoding happens at
        // emitShowText time). The accented char round-trips as UTF-8.
        $result2 = $r->wrapText("caf\xC3\xA9", 1000.0, $engine, 12.0);
        self::assertSame("caf\xC3\xA9", $result2->lines[0]);
    }

    public function testWrapTextEmptyStringSpecialCase(): void
    {
        $r = $this->renderer();
        // Spec: empty text is short-circuited at the render() level (Task 13). wrapText
        // still returns a deterministic result for empty strings.
        $result = $r->wrapText('', 1000.0, $this->engine(), 12.0);
        self::assertSame([''], $result->lines);
        self::assertSame([0.0], $result->widths);
        self::assertSame(0, $result->brokenWords);
    }

    public function testCondenseTextNoCompressionWhenFits(): void
    {
        $r = $this->renderer();
        // Helvetica "Hi" at 12pt is well below 1000pt -- scale stays 100.
        $result = $r->condenseText('Hi', 1000.0, $this->engine(), 12.0);
        self::assertSame(['Hi'], $result->lines);
        self::assertSame([100.0], $result->scales);
    }

    public function testCondenseTextScalesDownWhenOverflows(): void
    {
        $r = $this->renderer();
        // Force overflow: paraWidth is roughly Helvetica "Hello" ~ 27.34pt at 12pt.
        // innerW = 13.67 -> scale ~ 50.
        $result = $r->condenseText('Hello', 13.67, $this->engine(), 12.0);
        self::assertSame(['Hello'], $result->lines);
        self::assertCount(1, $result->scales);
        self::assertLessThan(100.0, $result->scales[0]);
        self::assertGreaterThan(0.0, $result->scales[0]);
    }

    public function testCondenseTextSplitsOnNewlines(): void
    {
        $r = $this->renderer();
        $result = $r->condenseText("a\nbb", 1000.0, $this->engine(Font::courier()), 10.0);
        self::assertSame(['a', 'bb'], $result->lines);
        self::assertSame([100.0, 100.0], $result->scales);
    }

    public function testCondenseTextEmptyParagraphScaleIs100(): void
    {
        $r = $this->renderer();
        $result = $r->condenseText('', 100.0, $this->engine(), 12.0);
        self::assertSame([''], $result->lines);
        self::assertSame([100.0], $result->scales);
    }

    public function testShrinkTextNoShrinkWhenFits(): void
    {
        $r = $this->renderer();
        $result = $r->shrinkText('Hi', 1000.0, $this->engine(), 12.0);
        self::assertSame(12.0, $result->effectiveSize);
        self::assertFalse($result->textOverflow);
    }

    public function testShrinkTextReducesSizeProportionally(): void
    {
        $r = $this->renderer();
        // Hello at Helvetica 12 ~ 27.336. innerW=13.67 -> ratio ~0.5 -> effectiveSize ~6.0.
        $result = $r->shrinkText('Hello', 13.67, $this->engine(), 12.0);
        self::assertEqualsWithDelta(6.0, $result->effectiveSize, 0.05);
        self::assertCount(1, $result->lines);
        self::assertCount(1, $result->widths);
    }

    public function testShrinkTextRatioBasedOnLongestLine(): void
    {
        $r = $this->renderer();
        // Two paragraphs; longest determines ratio.
        $result = $r->shrinkText("a\nHello", 13.67, $this->engine(), 12.0);
        self::assertCount(2, $result->lines);
        self::assertEqualsWithDelta(6.0, $result->effectiveSize, 0.05);
    }

    public function testShrinkTextExtremeNarrowSetsOverflow(): void
    {
        $r = $this->renderer();
        // 0.1pt is way below the originalSize/100 minSize threshold.
        $result = $r->shrinkText('Hello', 0.1, $this->engine(), 12.0);
        self::assertTrue($result->textOverflow);
        self::assertEqualsWithDelta(0.12, $result->effectiveSize, 0.001);
    }

    /**
     * @param callable(CellRenderer, ContentStream, string, Page): CellResult $configure
     * @return array{0: CellResult, 1: string}
     */
    private function renderAndStream(
        callable $configure,
        string $text = 'Hello',
        ?int $expectedLines = null,
    ): array {
        $stream = new ContentStream(842.0);
        $r = new CellRenderer(stream: $stream);
        $page = $this->page();
        $result = $configure($r, $stream, $text, $page);
        if ($expectedLines !== null) {
            self::assertSame($expectedLines, $result->lineCount);
        }
        return [$result, $stream->bytes()];
    }

    public function testRenderEmitsBeginEndText(): void
    {
        $engine = $this->engine();
        [$res, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine,
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
                align: \DragonOfMercy\PhpPdf\TextAlign::LEFT,
                verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::TOP,
                fit: \DragonOfMercy\PhpPdf\Fit::NONE,
                padding: CellPadding::all(2.0),
                fontShortName: 'F1',
                emittingPage: $p,
            ),
        );
        self::assertStringContainsString("BT\n", $bytes);
        self::assertStringContainsString("ET\n", $bytes);
        self::assertStringContainsString('/F1', $bytes);
        self::assertSame(1, $res->lineCount);
    }

    public function testRenderWrapsBlocksInQAndQ(): void
    {
        $engine = $this->engine();
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 50.0, y: 50.0, w: 100.0, h: null, text: 'Hi',
                border: null, fill: null, textColor: null,
                align: \DragonOfMercy\PhpPdf\TextAlign::LEFT, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::TOP,
                fit: \DragonOfMercy\PhpPdf\Fit::NONE, padding: CellPadding::all(2.0), fontShortName: 'F1', emittingPage: $p,
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
        $engine = $this->engine();
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 10.0, y: 20.0, w: 100.0, h: 30.0, text: '',
                border: null, fill: \DragonOfMercy\PhpPdf\Color::rgb(255, 0, 0),
                textColor: null,
                align: \DragonOfMercy\PhpPdf\TextAlign::LEFT, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::TOP,
                fit: \DragonOfMercy\PhpPdf\Fit::NONE, padding: CellPadding::all(2.0), fontShortName: 'F1', emittingPage: $p,
            ),
        );
        self::assertStringContainsString('1 0 0 rg', $bytes);
        self::assertStringContainsString("re\n", $bytes);
        self::assertStringContainsString("f\n", $bytes);
    }

    public function testRenderEmitsBordersForActiveSidesOnly(): void
    {
        $engine = $this->engine();
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 50.0, h: 20.0, text: '',
                border: \DragonOfMercy\PhpPdf\Border::sides(top: true, bottom: true),
                fill: null, textColor: null,
                align: \DragonOfMercy\PhpPdf\TextAlign::LEFT, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::TOP,
                fit: \DragonOfMercy\PhpPdf\Fit::NONE, padding: CellPadding::all(2.0), fontShortName: 'F1', emittingPage: $p,
            ),
        );
        // Two strokes, one for each active side.
        self::assertSame(2, substr_count($bytes, "S\n"));
    }

    public function testRenderSkipsBordersWhenBorderIsNull(): void
    {
        $engine = $this->engine();
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 50.0, h: 20.0, text: 'Hello',
                border: null, fill: null, textColor: null,
                align: \DragonOfMercy\PhpPdf\TextAlign::LEFT, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::TOP,
                fit: \DragonOfMercy\PhpPdf\Fit::NONE, padding: CellPadding::all(2.0), fontShortName: 'F1', emittingPage: $p,
            ),
        );
        self::assertSame(0, substr_count($bytes, "S\n"));
    }

    public function testRenderSkipsBordersWhenIsEmpty(): void
    {
        $engine = $this->engine();
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 50.0, h: 20.0, text: '',
                border: \DragonOfMercy\PhpPdf\Border::none(), fill: null, textColor: null,
                align: \DragonOfMercy\PhpPdf\TextAlign::LEFT, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::TOP,
                fit: \DragonOfMercy\PhpPdf\Fit::NONE, padding: CellPadding::all(2.0), fontShortName: 'F1', emittingPage: $p,
            ),
        );
        self::assertSame(0, substr_count($bytes, "S\n"));
    }

    public function testRenderEmitsDashedPattern(): void
    {
        $engine = $this->engine();
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 50.0, h: 20.0, text: '',
                border: \DragonOfMercy\PhpPdf\Border::all()->withStyle(\DragonOfMercy\PhpPdf\BorderStyle::DASHED),
                fill: null, textColor: null,
                align: \DragonOfMercy\PhpPdf\TextAlign::LEFT, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::TOP,
                fit: \DragonOfMercy\PhpPdf\Fit::NONE, padding: CellPadding::all(2.0), fontShortName: 'F1', emittingPage: $p,
            ),
        );
        self::assertStringContainsString('[3 3] 0 d', $bytes);
    }

    public function testRenderEmitsDottedPatternProportionalToWidth(): void
    {
        $engine = $this->engine();
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 50.0, h: 20.0, text: '',
                border: \DragonOfMercy\PhpPdf\Border::all()->withStyle(\DragonOfMercy\PhpPdf\BorderStyle::DOTTED)->withWidth(2.0),
                fill: null, textColor: null,
                align: \DragonOfMercy\PhpPdf\TextAlign::LEFT, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::TOP,
                fit: \DragonOfMercy\PhpPdf\Fit::NONE, padding: CellPadding::all(2.0), fontShortName: 'F1', emittingPage: $p,
            ),
        );
        // DOTTED at width=2 -> [2 4] dash pattern.
        self::assertStringContainsString('[2 4] 0 d', $bytes);
    }

    public function testRenderEmitsTzForCondense(): void
    {
        $engine = $this->engine();
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 13.67, h: 20.0, text: 'Hello',
                border: null, fill: null, textColor: null,
                align: \DragonOfMercy\PhpPdf\TextAlign::LEFT, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::TOP,
                fit: \DragonOfMercy\PhpPdf\Fit::CONDENSE, padding: CellPadding::all(0.0), fontShortName: 'F1', emittingPage: $p,
            ),
        );
        self::assertStringContainsString(' Tz', $bytes);
    }

    public function testRenderShrinkUsesSmallerTfSize(): void
    {
        $engine = $this->engine();
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 13.67, h: 20.0, text: 'Hello',
                border: null, fill: null, textColor: null,
                align: \DragonOfMercy\PhpPdf\TextAlign::LEFT, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::TOP,
                fit: \DragonOfMercy\PhpPdf\Fit::SHRINK, padding: CellPadding::all(0.0), fontShortName: 'F1', emittingPage: $p,
            ),
        );
        // Tf size will be ~6, never the original 12.
        self::assertMatchesRegularExpression('#/F1 [0-9]+(\.[0-9]+)? Tf#', $bytes);
        self::assertStringNotContainsString('/F1 12 Tf', $bytes);
    }

    public function testRenderEmptyTextSkipsBeginText(): void
    {
        $engine = $this->engine();
        [$res, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 50.0, h: 20.0, text: '',
                border: \DragonOfMercy\PhpPdf\Border::all(), fill: \DragonOfMercy\PhpPdf\Color::rgb(255, 255, 255),
                textColor: null,
                align: \DragonOfMercy\PhpPdf\TextAlign::LEFT, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::TOP,
                fit: \DragonOfMercy\PhpPdf\Fit::NONE, padding: CellPadding::all(2.0), fontShortName: 'F1', emittingPage: $p,
            ),
        );
        self::assertStringNotContainsString("BT\n", $bytes);
        self::assertSame(0, $res->lineCount);
    }

    public function testRenderResultGeometry(): void
    {
        $engine = $this->engine();
        [$res] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 50.0, y: 50.0, w: 200.0, h: 25.0, text: 'Hello',
                border: null, fill: null, textColor: null,
                align: \DragonOfMercy\PhpPdf\TextAlign::LEFT, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::TOP,
                fit: \DragonOfMercy\PhpPdf\Fit::NONE, padding: CellPadding::all(2.0), fontShortName: 'F1', emittingPage: $p,
            ),
        );
        self::assertSame(250.0, $res->x);
        self::assertSame(75.0, $res->y);
        self::assertSame(25.0, $res->height);
    }

    public function testRenderHeightActsAsMinHeight(): void
    {
        $engine = $this->engine();
        [$res] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 50.0, h: 5.0, text: "Hello\nworld\nhere",
                border: null, fill: null, textColor: null,
                align: \DragonOfMercy\PhpPdf\TextAlign::LEFT, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::TOP,
                fit: \DragonOfMercy\PhpPdf\Fit::NONE, padding: CellPadding::all(0.0), fontShortName: 'F1', emittingPage: $p,
            ),
        );
        // 3 lines of leading 14.4 each = 43.2, far exceeds h=5. Cell grows.
        self::assertGreaterThan(5.0, $res->height);
    }

    public function testRenderTextAlignCenterPositionsLineCorrectly(): void
    {
        $engine = $this->engine();
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 100.0, h: null, text: 'Hello',
                border: null, fill: null, textColor: null,
                align: \DragonOfMercy\PhpPdf\TextAlign::CENTER, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::TOP,
                fit: \DragonOfMercy\PhpPdf\Fit::NONE, padding: CellPadding::all(0.0), fontShortName: 'F1', emittingPage: $p,
            ),
        );
        // 'Hello' at Helvetica 12pt = 27.336pt. cellW = 100, padding = 0.
        // CENTER x = (100 - 27.336) / 2 = 36.332.
        self::assertMatchesRegularExpression('/1 0 0 -1 36\.33[0-9]+ [0-9.]+ Tm/', $bytes);
    }

    public function testRenderTextAlignRightPositionsLineCorrectly(): void
    {
        $engine = $this->engine();
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 100.0, h: null, text: 'Hello',
                border: null, fill: null, textColor: null,
                align: \DragonOfMercy\PhpPdf\TextAlign::RIGHT, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::TOP,
                fit: \DragonOfMercy\PhpPdf\Fit::NONE, padding: CellPadding::all(2.0), fontShortName: 'F1', emittingPage: $p,
            ),
        );
        // 'Hello' at Helvetica 12pt = 27.336pt. cellW = 100, padding = 2.
        // RIGHT x = 100 - 2 - 27.336 = 70.664.
        self::assertMatchesRegularExpression('/1 0 0 -1 70\.66[0-9]+ [0-9.]+ Tm/', $bytes);
    }

    public function testRenderVerticalAlignTopPositionsBaselineAtEm(): void
    {
        $engine = $this->engine();
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 100.0, h: 40.0, text: 'Aubord',
                border: null, fill: null, textColor: null,
                align: \DragonOfMercy\PhpPdf\TextAlign::LEFT, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::TOP,
                fit: \DragonOfMercy\PhpPdf\Fit::NONE, padding: CellPadding::all(0.0), fontShortName: 'F1', emittingPage: $p,
            ),
        );
        // Bbox-safe TOP: baseline = cellY + paddingTop + effectiveSize.
        // padding=0, size=12 -> baseline = 12.
        self::assertMatchesRegularExpression('/1 0 0 -1 [0-9.]+ 12(?:\.0+)? Tm/', $bytes);
    }

    public function testRenderVerticalAlignMiddlePositionsBaseline(): void
    {
        $engine = $this->engine();
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 100.0, h: 40.0, text: 'Hello',
                border: null, fill: null, textColor: null,
                align: \DragonOfMercy\PhpPdf\TextAlign::LEFT, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::MIDDLE,
                fit: \DragonOfMercy\PhpPdf\Fit::NONE, padding: CellPadding::all(0.0), fontShortName: 'F1', emittingPage: $p,
            ),
        );
        // Centre-on-cap-height: capHeight(8.616) at Helvetica 12pt. cellH = 40.
        // baseline = 0 + (40 + 8.616) / 2 = 24.308.
        self::assertMatchesRegularExpression('/1 0 0 -1 [0-9.]+ 24\.30[0-9]+ Tm/', $bytes);
    }

    public function testRenderVerticalAlignBottomPositionsBaseline(): void
    {
        $engine = $this->engine();
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 100.0, h: 40.0, text: 'Hello',
                border: null, fill: null, textColor: null,
                align: \DragonOfMercy\PhpPdf\TextAlign::LEFT, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::BOTTOM,
                fit: \DragonOfMercy\PhpPdf\Fit::NONE, padding: CellPadding::all(2.0), fontShortName: 'F1', emittingPage: $p,
            ),
        );
        // Bbox-safe BOTTOM: lastBaseline = cellY + cellH - paddingBottom - |descent|
        // For Helvetica 12pt, |descent| = 207/1000*12 = 2.484. cellH=40, padding=2
        // -> lastBaseline = 40 - 2 - 2.484 = 35.516.
        self::assertMatchesRegularExpression('/1 0 0 -1 [0-9.]+ 35\.51[0-9]+ Tm/', $bytes);
    }

    public function testRenderTextColorEmittedBeforeBeginText(): void
    {
        $engine = $this->engine();
        [, $bytes] = $this->renderAndStream(
            static fn (CellRenderer $r, ContentStream $s, string $t, Page $p): \DragonOfMercy\PhpPdf\CellResult => $r->render(
                engine: $engine, size: 12.0, customLeading: null,
                x: 0.0, y: 0.0, w: 100.0, h: null, text: 'Hello',
                border: null, fill: null, textColor: \DragonOfMercy\PhpPdf\Color::rgb(255, 0, 0),
                align: \DragonOfMercy\PhpPdf\TextAlign::LEFT, verticalAlign: \DragonOfMercy\PhpPdf\VerticalAlign::TOP,
                fit: \DragonOfMercy\PhpPdf\Fit::NONE, padding: CellPadding::all(0.0), fontShortName: 'F1', emittingPage: $p,
            ),
        );
        // textColor red emits "1 0 0 rg" before BT. The color is set inside the q/Q
        // wrap and inside the BT/ET block.
        $rgPos = strpos($bytes, '1 0 0 rg');
        $btPos = strpos($bytes, "BT\n");
        self::assertNotFalse($rgPos);
        self::assertNotFalse($btPos);
        self::assertLessThan($btPos, $rgPos);
    }

    public function testCellWithCustomFontRendersText(): void
    {
        $path = __DIR__ . '/../../Golden/fixtures/fonts/FreeSans.ttf';
        if (!is_file($path)) {
            self::markTestSkipped('FreeSans fixture not present');
        }
        $pdf = new \DragonOfMercy\PhpPdf\Document();
        $pdf->registerFontFamily('FS', regular: $path);
        $page = $pdf->addPage();
        $page->setFont(\DragonOfMercy\PhpPdf\Font::custom('FS'), 14);
        $page->cell(x: 10, y: 20, w: 80, h: 20, text: 'Hello FS');
        $bytes = $page->contentStream()->bytes();
        self::assertMatchesRegularExpression('/<[0-9A-F]{4,}> Tj/', $bytes);
    }

    public function testCellWithCustomFontAutoSizesViaCustomMetrics(): void
    {
        $path = __DIR__ . '/../../Golden/fixtures/fonts/FreeSans.ttf';
        if (!is_file($path)) {
            self::markTestSkipped('FreeSans fixture not present');
        }
        $pdf = new \DragonOfMercy\PhpPdf\Document();
        $pdf->registerFontFamily('FS', regular: $path);
        $page = $pdf->addPage();
        $page->setFont(\DragonOfMercy\PhpPdf\Font::custom('FS'), 12);
        $page->cell(x: 10, y: 20, text: 'A');
        $bytes = $page->contentStream()->bytes();
        self::assertNotEmpty($bytes);
    }

    public function testCellAutoSizeWithCustomFontMatchesStringWidth(): void
    {
        $path = __DIR__ . '/../../Golden/fixtures/fonts/FreeSans.ttf';
        if (!is_file($path)) {
            self::markTestSkipped('FreeSans fixture not present');
        }
        $pdf = new \DragonOfMercy\PhpPdf\Document();
        $pdf->registerFontFamily('FS', regular: $path);
        $page = $pdf->addPage();
        $page->setFont(\DragonOfMercy\PhpPdf\Font::custom('FS'), 12);
        $w = $page->stringWidth('Hello');
        self::assertGreaterThan(0.0, $w);
    }
}
