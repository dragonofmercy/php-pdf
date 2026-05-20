<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Fit;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\FontEngine;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Font\StandardFontEngine;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\ImageRegistry;
use DragonOfMercy\PhpPdf\LineCap;
use DragonOfMercy\PhpPdf\LineJoin;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\PageMargins;
use DragonOfMercy\PhpPdf\Path;
use DragonOfMercy\PhpPdf\PathOperation;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Tests\Support\Fakes\MeasureCountingFontEngine;
use DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class PageTest extends TestCase
{
    private function content(Page $page): string
    {
        $bytes = $page->contentStream()->bytes();
        if ($bytes === '') {
            return '';
        }
        $prefix = "1 0 0 -1 0 841.89 cm\n";
        self::assertStringStartsWith($prefix, $bytes);
        return substr($bytes, strlen($prefix));
    }

    private function page(): Page
    {
        return new Page(
            pageWidth: 595.28,
            pageHeight: 841.89,
            fontRegistry: new FontRegistry(),
            metricsRegistry: new MetricsRegistry(),
            imageRegistry: new ImageRegistry(),
        );
    }

    public function testLineAppendsMoveAndLineThenStrokes(): void
    {
        $page = $this->page();
        $op = $page->line(10, 20, 30, 40);
        self::assertInstanceOf(PathOperation::class, $op);
        $op->stroke();
        self::assertSame("10 20 m\n30 40 l\nS\n", $this->content($page));
    }

    public function testRectAppendsReThenFills(): void
    {
        $page = $this->page();
        $page->rect(5, 10, 100, 50)->fill();
        self::assertSame("5 10 100 50 re\nf\n", $this->content($page));
    }

    public function testCircleUsesFourCubicBeziers(): void
    {
        $page = $this->page();
        $page->circle(100, 100, 50)->stroke();
        $content = $this->content($page);
        self::assertSame(1, substr_count($content, " m\n"));
        self::assertSame(4, substr_count($content, " c\n"));
        self::assertStringContainsString("h\n", $content);
        self::assertStringEndsWith("S\n", $content);
    }

    public function testPathBuilderReturnsPath(): void
    {
        $page = $this->page();
        $path = $page->path();
        self::assertInstanceOf(Path::class, $path);
    }

    public function testSetStrokeColorEmitsRG(): void
    {
        $page = $this->page();
        $page->setStrokeColor(Color::rgb(255, 0, 0));
        self::assertSame("1 0 0 RG\n", $this->content($page));
    }

    public function testSetFillColorEmitsRg(): void
    {
        $page = $this->page();
        $page->setFillColor(Color::hex('#00ff00'));
        self::assertSame("0 1 0 rg\n", $this->content($page));
    }

    public function testSetLineWidth(): void
    {
        $page = $this->page();
        $page->setLineWidth(2.5);
        self::assertSame("2.5 w\n", $this->content($page));
    }

    public function testSetLineCapAndJoin(): void
    {
        $page = $this->page();
        $page->setLineCap(LineCap::ROUND);
        $page->setLineJoin(LineJoin::BEVEL);
        self::assertSame("1 J\n2 j\n", $this->content($page));
    }

    public function testSetDashPattern(): void
    {
        $page = $this->page();
        $page->setDashPattern([3.0, 2.0], 0.5);
        self::assertSame("[3 2] 0.5 d\n", $this->content($page));
    }

    public function testTranslateRotateScale(): void
    {
        $page = $this->page();
        $page->translate(10, 20);
        $page->scale(2, 3);
        $page->rotate(90);
        self::assertSame(
            "1 0 0 1 10 20 cm\n2 0 0 3 0 0 cm\n0 -1 1 0 0 0 cm\n",
            $this->content($page),
        );
    }

    public function testSaveAndRestore(): void
    {
        $page = $this->page();
        $page->save();
        $page->rect(0, 0, 10, 10)->fill();
        $page->restore();
        self::assertSame("q\n0 0 10 10 re\nf\nQ\n", $this->content($page));
    }

    public function testStateSettersAreChainable(): void
    {
        $page = $this->page();
        $result = $page
            ->setStrokeColor(Color::rgb(255, 0, 0))
            ->setLineWidth(1)
            ->save()
            ->translate(10, 10)
            ->restore();
        self::assertSame($page, $result);
    }

    public function testSetFontDoesNotRegisterUntilTextIsCalled(): void
    {
        $registry = new FontRegistry();
        $page = new Page(pageWidth: 595.28, pageHeight: 841.89, fontRegistry: $registry, metricsRegistry: new MetricsRegistry(), imageRegistry: new ImageRegistry());

        $page->setFont(Font::helvetica()->bold(), 14);

        // setFont alone does NOT register — lazy until text() is called
        self::assertTrue($registry->isEmpty());
        self::assertSame([], $page->fontsUsed());
        // Also no bytes emitted — Tf goes inside text()'s BT/ET block
        self::assertSame('', $this->content($page));
    }

    public function testFirstTextCallRegistersFontInRegistryAndOnPage(): void
    {
        $registry = new FontRegistry();
        $page = new Page(pageWidth: 595.28, pageHeight: 841.89, fontRegistry: $registry, metricsRegistry: new MetricsRegistry(), imageRegistry: new ImageRegistry());

        $page->setFont(Font::helvetica()->bold(), 14);
        self::assertTrue($registry->isEmpty());

        $page->text(10, 10, 'Hi');

        self::assertFalse($registry->isEmpty());
        self::assertSame('Helvetica-Bold', $registry->registeredFonts()[0]->pdfName());
        self::assertSame('Helvetica-Bold', $page->fontsUsed()[0]->pdfName());
    }

    public function testTextThrowsIfNoFontSet(): void
    {
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $this->expectExceptionMessage('setFont');
        $page = $this->page();
        $page->text(50, 50, 'Hello');
    }

    public function testSimpleTextEmitsFullBlock(): void
    {
        $page = $this->page();
        $page->setFont(Font::helvetica(), 12);
        $page->text(100, 200, 'Hello');

        $content = $this->content($page);
        // Expected content block (leading = 12 * 1.2 = 14.4)
        self::assertStringContainsString("BT\n", $content);
        self::assertStringContainsString("/F1 12 Tf\n", $content);
        self::assertStringContainsString("14.4 TL\n", $content);
        self::assertStringContainsString("1 0 0 -1 100 200 Tm\n", $content);
        self::assertStringContainsString("(Hello) Tj\n", $content);
        self::assertStringContainsString("ET\n", $content);
    }

    public function testMultilineTextUsesApostropheOperator(): void
    {
        $page = $this->page();
        $page->setFont(Font::helvetica(), 10);
        $page->text(0, 0, "A\nB\nC");

        $content = $this->content($page);
        self::assertStringContainsString("(A) Tj\n", $content);
        self::assertStringContainsString("(B) '\n", $content);
        self::assertStringContainsString("(C) '\n", $content);
    }

    public function testSetLeadingOverridesDefault(): void
    {
        $page = $this->page();
        $page->setFont(Font::helvetica(), 10);
        $page->setLeading(20);
        $page->text(0, 0, 'Hi');

        $content = $this->content($page);
        self::assertStringContainsString("20 TL\n", $content);
        self::assertStringNotContainsString("12 TL\n", $content);
    }

    public function testSetFontResetsLeadingOverride(): void
    {
        $page = $this->page();
        $page->setFont(Font::helvetica(), 10);
        $page->setLeading(20);
        // Change font — previous override should reset
        $page->setFont(Font::helvetica(), 30);
        $page->text(0, 0, 'Hi');

        $content = $this->content($page);
        // New default: 30 * 1.2 = 36
        self::assertStringContainsString("36 TL\n", $content);
        self::assertStringNotContainsString("20 TL\n", $content);
    }

    public function testTextEncodesUnicodeViaWinAnsi(): void
    {
        $page = $this->page();
        $page->setFont(Font::helvetica(), 10);
        $page->text(0, 0, 'Café');

        $content = $this->content($page);
        // "Café" WinAnsi bytes: C=0x43, a=0x61, f=0x66, é=0xE9
        self::assertStringContainsString("(Caf\xE9) Tj\n", $content);
    }

    public function testSetFontThrowsOnZeroSize(): void
    {
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $this->expectExceptionMessage('positive');
        $page = $this->page();
        $page->setFont(Font::helvetica(), 0);
    }

    public function testSetFontThrowsOnNegativeSize(): void
    {
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $this->expectExceptionMessage('positive');
        $page = $this->page();
        $page->setFont(Font::helvetica(), -5.0);
    }

    public function testGetFontReturnsCurrentFont(): void
    {
        $page = $this->page();
        $font = Font::times()->bold();
        $page->setFont($font, 14);
        self::assertSame($font, $page->getFont());
    }

    public function testGetFontThrowsWhenNoFontSet(): void
    {
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $this->expectExceptionMessage('No font set');
        $this->page()->getFont();
    }

    public function testGetFontSizeReturnsCurrentSize(): void
    {
        $page = $this->page();
        $page->setFont(Font::helvetica(), 14.5);
        self::assertSame(14.5, $page->getFontSize());
    }

    public function testGetFontSizeThrowsWhenNoFontSet(): void
    {
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $this->expectExceptionMessage('No font set');
        $this->page()->getFontSize();
    }

    public function testSetFontReusesCurrentSizeWhenSizeOmitted(): void
    {
        $page = $this->page();
        $page->setFont(Font::helvetica(), 18);
        $newFont = Font::times()->italic();
        $page->setFont($newFont);
        self::assertSame($newFont, $page->getFont());
        self::assertSame(18.0, $page->getFontSize());
    }

    public function testSetFontWithoutSizeThrowsWhenNoCurrentSize(): void
    {
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $this->expectExceptionMessage('Font size is required');
        $this->page()->setFont(Font::helvetica());
    }

    public function testFontsUsedAccessorReturnsRegisteredForThisPage(): void
    {
        $page = $this->page();
        $page->setFont(Font::helvetica(), 10);
        $page->text(0, 0, 'A');
        $page->setFont(Font::times(), 12);
        $page->text(0, 20, 'B');

        $used = $page->fontsUsed();
        self::assertCount(2, $used);
        self::assertContains('Helvetica', array_map(
            static fn (Font $f): string => $f->pdfName(),
            $used,
        ));
        self::assertContains('Times-Roman', array_map(
            static fn (Font $f): string => $f->pdfName(),
            $used,
        ));
    }

    public function testStringWidthWithExplicitFontAndSize(): void
    {
        $page = new Page(
            pageWidth: 595.0,
            pageHeight: 842.0,
            fontRegistry: new FontRegistry(),
            metricsRegistry: new MetricsRegistry(),
            imageRegistry: new ImageRegistry(),
        );
        // Helvetica space=278/1000 em, 'H'=722, 'e'=556, 'l'=222, 'l'=222, 'o'=556 => "Hello" = 2278
        // At size 12: 2278 / 1000 * 12 = 27.336
        self::assertEqualsWithDelta(27.336, $page->stringWidth('Hello', Font::helvetica(), 12.0), 0.0001);
    }

    public function testStringWidthUsesCurrentFontWhenArgsOmitted(): void
    {
        $page = new Page(
            pageWidth: 595.0,
            pageHeight: 842.0,
            fontRegistry: new FontRegistry(),
            metricsRegistry: new MetricsRegistry(),
            imageRegistry: new ImageRegistry(),
        );
        $page->setFont(Font::helvetica(), 12.0);
        self::assertEqualsWithDelta(27.336, $page->stringWidth('Hello'), 0.0001);
    }

    public function testStringWidthEmptyStringIsZero(): void
    {
        $page = new Page(
            pageWidth: 595.0,
            pageHeight: 842.0,
            fontRegistry: new FontRegistry(),
            metricsRegistry: new MetricsRegistry(),
            imageRegistry: new ImageRegistry(),
        );
        $page->setFont(Font::helvetica(), 12.0);
        self::assertSame(0.0, $page->stringWidth(''));
    }

    public function testStringWidthMultilineReturnsMaxLineWidth(): void
    {
        $page = new Page(
            pageWidth: 595.0,
            pageHeight: 842.0,
            fontRegistry: new FontRegistry(),
            metricsRegistry: new MetricsRegistry(),
            imageRegistry: new ImageRegistry(),
        );
        $page->setFont(Font::courier(), 10.0);
        // Courier is monospace 600/1000 em. "a" = 600, "abc" = 1800. At size 10: max = 1800/1000*10 = 18.0
        self::assertEqualsWithDelta(18.0, $page->stringWidth("a\nabc"), 0.0001);
    }

    public function testStringWidthCrlfMatchesLfOnlyInput(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::courier(), 10.0);
        // CRLF and CR should normalize to LF; without normalization the
        // trailing \r on the first paragraph encodes as '?' and inflates width.
        self::assertSame(
            $page->stringWidth("a\nabc"),
            $page->stringWidth("a\r\nabc"),
        );
        self::assertSame(
            $page->stringWidth("a\nabc"),
            $page->stringWidth("a\rabc"),
        );
    }

    public function testStringWidthThrowsWhenNoStateAndNoArgs(): void
    {
        $page = new Page(
            pageWidth: 595.0,
            pageHeight: 842.0,
            fontRegistry: new FontRegistry(),
            metricsRegistry: new MetricsRegistry(),
            imageRegistry: new ImageRegistry(),
        );
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $page->stringWidth('Hello');
    }

    public function testGetCellsPaddingDefaultIsTwo(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        self::assertEquals(\DragonOfMercy\PhpPdf\CellPadding::all(2.0), $page->getCellsPadding());
    }

    public function testSetCellsPaddingChangesDefault(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setCellsPadding(5.5);
        self::assertEquals(\DragonOfMercy\PhpPdf\CellPadding::all(5.5), $page->getCellsPadding());
    }

    public function testSetCellsPaddingNegativeThrows(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $page->setCellsPadding(-1.0);
    }

    public function testSetCellsPaddingAcceptsCellPaddingObject(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setCellsPadding(\DragonOfMercy\PhpPdf\CellPadding::sides(top: 1.0, right: 2.0, bottom: 3.0, left: 4.0));
        $p = $page->getCellsPadding();
        self::assertSame(1.0, $p->top);
        self::assertSame(2.0, $p->right);
        self::assertSame(3.0, $p->bottom);
        self::assertSame(4.0, $p->left);
    }

    public function testCellPerSidePaddingAffectsAutoWidth(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::courier(), 10);
        // Courier monospace 600/1000em -> "Hi" = 1200 -> 12pt at size 10.
        // Per-side padding left=3, right=5 -> auto width = 12 + 8 = 20.
        $r = $page->cell(
            x: 0,
            y: 0,
            text: 'Hi',
            padding: \DragonOfMercy\PhpPdf\CellPadding::sides(top: 0, right: 5, bottom: 0, left: 3),
        );
        self::assertEqualsWithDelta(20.0, $r->x, 0.0001); // x_start (0) + cell width (20)
    }

    public function testCellPaddingObjectAcceptedInline(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        // Smoke: passing CellPadding inline should not throw and should render.
        $r = $page->cell(
            x: 10, y: 10, w: 60, h: 30,
            text: 'X',
            padding: \DragonOfMercy\PhpPdf\CellPadding::symmetric(2, 4),
        );
        self::assertSame(70.0, $r->x);
    }

    public function testCellWithoutSetFontThrows(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $page->cell(x: 50, y: 50, w: 100, text: 'Hello');
    }

    public function testCellWidthZeroThrows(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $page->cell(x: 50, y: 50, w: 0.0, text: 'Hello');
    }

    public function testCellHeightNegativeThrows(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $page->cell(x: 50, y: 50, w: 100, h: -1.0, text: 'Hello');
    }

    public function testCellPaddingNegativeThrows(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $page->cell(x: 50, y: 50, w: 100, text: 'Hello', padding: -2.0);
    }

    public function testCellHappyPathReturnsCellResult(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        $r = $page->cell(x: 50, y: 50, w: 200, h: 25, text: 'Hello');
        self::assertSame(250.0, $r->x);
        self::assertSame(75.0, $r->y);
        self::assertSame(25.0, $r->height);
    }

    public function testCellRegistersFontInRegistry(): void
    {
        $registry = new FontRegistry();
        $page = new Page(595, 842, $registry, new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        self::assertTrue($registry->isEmpty(), 'Font should not be registered before cell()');
        $page->cell(x: 0, y: 0, w: 100, text: 'Hi');
        self::assertFalse($registry->isEmpty(), 'Font should be registered after cell()');
    }

    public function testCellEmptyTextDoesNotRegisterFont(): void
    {
        $registry = new FontRegistry();
        $page = new Page(595, 842, $registry, new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        $page->cell(x: 0, y: 0, w: 100, text: '', border: \DragonOfMercy\PhpPdf\Border::all()->withWidth(0.5));
        self::assertTrue($registry->isEmpty());
    }

    public function testCellAutoWidthFromText(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        $expected = $page->stringWidth('Hello') + 2 * 1.5;
        $r = $page->cell(x: 10, y: 10, text: 'Hello', padding: 1.5);
        self::assertEqualsWithDelta($expected + 10, $r->x, 0.0001);
    }

    public function testCellAutoWidthUsesDefaultPadding(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        $page->setCellsPadding(2.0);
        $expected = $page->stringWidth('Hi') + 2 * 2.0;
        $r = $page->cell(x: 0, y: 0, text: 'Hi');
        self::assertEqualsWithDelta($expected, $r->x, 0.0001);
    }

    public function testCellAutoWidthEmptyTextThrows(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $this->expectExceptionMessage('Cell width is required when text is empty');
        $page->cell(x: 50, y: 50, border: \DragonOfMercy\PhpPdf\Border::all());
    }

    public function testCellLnRightAdvancesCursorHorizontally(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        // h: 30 with padding 0 dominates the auto text-height (14.4), so
        // cellHeight stays predictable at 30.
        $page->cell(x: 20, y: 30, w: 40, h: 30, text: 'A', padding: 0, ln: \DragonOfMercy\PhpPdf\NextPosition::RIGHT);
        $r = $page->cell(w: 25, h: 30, text: 'B', padding: 0);
        self::assertSame(85.0, $r->x);   // 20 + 40 + 25
        self::assertSame(60.0, $r->y);   // 30 + 30
    }

    public function testCellLnNewlineReturnsToRowStart(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        $page->cell(x: 20, y: 30, w: 40, h: 30, text: 'A', padding: 0, ln: \DragonOfMercy\PhpPdf\NextPosition::RIGHT);
        $page->cell(w: 25, h: 30, text: 'B', padding: 0, ln: \DragonOfMercy\PhpPdf\NextPosition::NEWLINE);
        $r = $page->cell(w: 30, h: 30, text: 'C', padding: 0);
        self::assertSame(50.0, $r->x);   // back to row start (20) + 30
        self::assertSame(90.0, $r->y);   // 30 + 30 + 30
    }

    public function testCellLnBelowKeepsColumn(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        $page->cell(x: 80, y: 30, w: 40, h: 30, text: 'A', padding: 0, ln: \DragonOfMercy\PhpPdf\NextPosition::BELOW);
        $r = $page->cell(w: 40, h: 30, text: 'B', padding: 0);
        self::assertSame(120.0, $r->x); // 80 + 40
        self::assertSame(90.0, $r->y);  // 30 + 30 + 30
    }

    public function testCellWithoutCursorAndOmittedXThrows(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $this->expectExceptionMessage('no cursor set');
        $page->cell(w: 30, h: 10, text: 'A');
    }

    public function testPageConstructorAcceptsDefaultFont(): void
    {
        $page = new Page(
            pageWidth: 595,
            pageHeight: 842,
            fontRegistry: new FontRegistry(),
            metricsRegistry: new MetricsRegistry(),
            imageRegistry: new ImageRegistry(),
            defaultFont: Font::courier(),
            defaultSize: 9.0,
        );
        // No setFont() call: the constructor default lets cell() proceed.
        $page->cell(x: 0, y: 0, w: 50, h: 30, text: 'X', padding: 0);
        self::assertMatchesRegularExpression('#9(\.\d+)? Tf#', $page->contentStream()->bytes());
    }

    public function testPageConstructorRejectsHalfSpecifiedDefaultFont(): void
    {
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        new Page(
            pageWidth: 595,
            pageHeight: 842,
            fontRegistry: new FontRegistry(),
            metricsRegistry: new MetricsRegistry(),
            imageRegistry: new ImageRegistry(),
            defaultFont: Font::helvetica(),
            // defaultSize omitted: must throw rather than silently pick a size.
        );
    }

    public function testCellNormalizesCrlfInText(): void
    {
        $a = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $a->setFont(Font::helvetica(), 12);
        $a->cell(x: 20, y: 30, w: 80, h: 40, text: "Chalet Kitoko\nRoute des Narcisses", padding: 0);

        $b = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $b->setFont(Font::helvetica(), 12);
        $b->cell(x: 20, y: 30, w: 80, h: 40, text: "Chalet Kitoko\r\nRoute des Narcisses", padding: 0);

        // CRLF must produce the exact same content stream as LF (no stray '?'
        // from the trailing \r being WinAnsi-encoded as a control character).
        self::assertSame($a->contentStream()->bytes(), $b->contentStream()->bytes());
        self::assertStringNotContainsString('Kitoko?', $a->contentStream()->bytes());
    }

    public function testTextNormalizesCrlf(): void
    {
        $a = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $a->setFont(Font::helvetica(), 12);
        $a->text(20, 30, "Chalet Kitoko\nRoute des Narcisses");

        $b = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $b->setFont(Font::helvetica(), 12);
        $b->text(20, 30, "Chalet Kitoko\r\nRoute des Narcisses");

        self::assertSame($a->contentStream()->bytes(), $b->contentStream()->bytes());
    }

    public function testGetXThrowsWhenCursorUnset(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $this->expectExceptionMessage('No cursor set');
        $page->getX();
    }

    public function testGetYThrowsWhenCursorUnset(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $page->getY();
    }

    public function testSetXSetYRoundTrip(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setX(42.0)->setY(73.0);
        self::assertSame(42.0, $page->getX());
        self::assertSame(73.0, $page->getY());
    }

    public function testSetXYRoundTrip(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setXY(10.5, 20.25);
        self::assertSame(10.5, $page->getX());
        self::assertSame(20.25, $page->getY());
    }

    public function testGetPageWidthAndHeightReturnMmForDefaultA4Document(): void
    {
        $page = (new Document())->addPage();
        self::assertEqualsWithDelta(210.0, $page->getPageWidth(), 1e-9);
        self::assertEqualsWithDelta(297.0, $page->getPageHeight(), 1e-9);
    }

    public function testGetPageWidthAndHeightReturnRawPointsWhenUnitIsPt(): void
    {
        $page = (new Document(Unit::PT))->addPage([200.0, 100.0]);
        self::assertSame(200.0, $page->getPageWidth());
        self::assertSame(100.0, $page->getPageHeight());
    }

    public function testGetPageWidthIsConsistentWithPageWidthPropertyAcrossUnit(): void
    {
        $page = (new Document())->addPage();
        self::assertEqualsWithDelta($page->pageWidth, Unit::MM->toPoints($page->getPageWidth()), 1e-9);
        self::assertEqualsWithDelta($page->pageHeight, Unit::MM->toPoints($page->getPageHeight()), 1e-9);
    }

    public function testCellAfterSetXYUsesCursor(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        $page->setXY(40, 60);
        $r = $page->cell(w: 30, h: 30, text: 'A', padding: 0);
        self::assertSame(70.0, $r->x);   // 40 + 30
        self::assertSame(90.0, $r->y);   // 60 + 30
    }

    public function testSetXResetsLineStartForNewline(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        $page->cell(x: 20, y: 30, w: 40, h: 30, text: 'A', padding: 0, ln: \DragonOfMercy\PhpPdf\NextPosition::RIGHT);
        $page->setX(100); // redefines the row-start anchor
        $page->cell(w: 30, h: 30, text: 'B', padding: 0, ln: \DragonOfMercy\PhpPdf\NextPosition::NEWLINE);
        $r = $page->cell(w: 20, h: 30, text: 'C', padding: 0);
        self::assertSame(120.0, $r->x); // 100 (new row start) + 20
        self::assertSame(90.0, $r->y);  // 30 + 30 from NEWLINE
    }

    public function testCellWithLnNoneDoesNotMoveCursor(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        $page->cell(x: 20, y: 30, w: 40, h: 30, text: 'A', padding: 0, ln: \DragonOfMercy\PhpPdf\NextPosition::RIGHT);
        // ln: NONE preserves the cursor at (60, 30); the third call still
        // resolves from that same anchor (no drift).
        $page->cell(w: 25, h: 30, text: 'B', padding: 0, ln: \DragonOfMercy\PhpPdf\NextPosition::NONE);
        $r = $page->cell(w: 25, h: 30, text: 'C', padding: 0, ln: \DragonOfMercy\PhpPdf\NextPosition::NONE);
        self::assertSame(85.0, $r->x);   // 20 + 40 + 25, same as previous call
        self::assertSame(60.0, $r->y);
    }

    public function testCellDefaultLnRightAdvancesCursor(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        // First cell pins x and y; ln defaults to RIGHT so the cursor is now at
        // the right edge of the cell (60, 30).
        $page->cell(x: 20, y: 30, w: 40, h: 30, text: 'A', padding: 0);
        self::assertSame(60.0, $page->getX());
        self::assertSame(30.0, $page->getY());
        // Second cell uses the moved cursor as its anchor; default RIGHT again.
        $page->cell(w: 25, h: 30, text: 'B', padding: 0);
        self::assertSame(85.0, $page->getX());
        self::assertSame(30.0, $page->getY());
    }

    public function testImageReturnsSelfForChaining(): void
    {
        $doc = new \DragonOfMercy\PhpPdf\Document(\DragonOfMercy\PhpPdf\Unit::PT);
        $page = $doc->addPage();
        $img = Image::fromBytes(TestImageFactory::pngRgb(2, 2));
        self::assertSame($page, $page->image($img, x: 10, y: 10, w: 100, h: 50));
    }

    public function testImageWithExplicitDimensions(): void
    {
        $doc = new \DragonOfMercy\PhpPdf\Document(\DragonOfMercy\PhpPdf\Unit::PT);
        $page = $doc->addPage();
        $img = Image::fromBytes(TestImageFactory::pngRgb(2, 2));
        $page->image($img, x: 10, y: 20, w: 100, h: 50);
        $bytes = $page->contentStream()->bytes();
        self::assertStringContainsString('/Im1 Do', $bytes);
        // CTM: 100 0 0 -50 10 70 cm  (y + effH = 20 + 50 = 70)
        self::assertStringContainsString('100 0 0 -50 10 70 cm', $bytes);
    }

    public function testImageWidthOnlyDerivesHeightFromAspect(): void
    {
        $doc = new \DragonOfMercy\PhpPdf\Document(\DragonOfMercy\PhpPdf\Unit::PT);
        $page = $doc->addPage();
        // Source 10x5 -> aspect 2:1. w=200 -> h derived = 100.
        $img = Image::fromBytes(TestImageFactory::pngRgb(width: 10, height: 5));
        $page->image($img, x: 0, y: 0, w: 200);
        self::assertStringContainsString('200 0 0 -100 0 100 cm', $page->contentStream()->bytes());
    }

    public function testImageHeightOnlyDerivesWidthFromAspect(): void
    {
        $doc = new \DragonOfMercy\PhpPdf\Document(\DragonOfMercy\PhpPdf\Unit::PT);
        $page = $doc->addPage();
        // Source 10x5 -> aspect 2:1. h=50 -> w derived = 100.
        $img = Image::fromBytes(TestImageFactory::pngRgb(width: 10, height: 5));
        $page->image($img, x: 0, y: 0, h: 50);
        self::assertStringContainsString('100 0 0 -50 0 50 cm', $page->contentStream()->bytes());
    }

    public function testImageNeitherDimensionUsesIntrinsicSize(): void
    {
        $doc = new \DragonOfMercy\PhpPdf\Document(\DragonOfMercy\PhpPdf\Unit::PT);
        $page = $doc->addPage();
        $img = Image::fromBytes(TestImageFactory::pngRgb(width: 64, height: 32));
        $page->image($img, x: 5, y: 10);
        self::assertStringContainsString('64 0 0 -32 5 42 cm', $page->contentStream()->bytes());
    }

    public function testImageThrowsOnNonPositiveWidth(): void
    {
        $doc = new \DragonOfMercy\PhpPdf\Document(\DragonOfMercy\PhpPdf\Unit::PT);
        $page = $doc->addPage();
        $img = Image::fromBytes(TestImageFactory::pngRgb(2, 2));
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Image width must be positive');
        $page->image($img, x: 0, y: 0, w: 0);
    }

    public function testImageThrowsOnNonPositiveHeight(): void
    {
        $doc = new \DragonOfMercy\PhpPdf\Document(\DragonOfMercy\PhpPdf\Unit::PT);
        $page = $doc->addPage();
        $img = Image::fromBytes(TestImageFactory::pngRgb(2, 2));
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Image height must be positive');
        $page->image($img, x: 0, y: 0, h: -5);
    }

    public function testImageWithStringPath(): void
    {
        $doc = new \DragonOfMercy\PhpPdf\Document(\DragonOfMercy\PhpPdf\Unit::PT);
        $page = $doc->addPage();
        $path = sys_get_temp_dir() . '/phppdf-page-' . uniqid() . '.png';
        file_put_contents($path, TestImageFactory::pngRgb(width: 20, height: 10));
        try {
            $page->image($path, x: 0, y: 0);
            $bytes = $page->contentStream()->bytes();
            self::assertStringContainsString('/Im1 Do', $bytes);
            self::assertStringContainsString('20 0 0 -10 0 10 cm', $bytes);
        } finally {
            @unlink($path);
        }
    }

    public function testTwoImageCallsWithSamePathReuseShortName(): void
    {
        $doc = new \DragonOfMercy\PhpPdf\Document(\DragonOfMercy\PhpPdf\Unit::PT);
        $page = $doc->addPage();
        $path = sys_get_temp_dir() . '/phppdf-page-' . uniqid() . '.png';
        file_put_contents($path, TestImageFactory::pngRgb(2, 2));
        try {
            $page->image($path, x: 0, y: 0, w: 50, h: 50);
            $page->image($path, x: 60, y: 0, w: 30, h: 30);
            $bytes = $page->contentStream()->bytes();
            // Both placements reference the same XObject /Im1.
            self::assertSame(2, substr_count($bytes, '/Im1 Do'));
            // No /Im2 (same path -> same registration).
            self::assertStringNotContainsString('/Im2', $bytes);
            self::assertSame(['Im1'], $page->imagesUsed());
        } finally {
            @unlink($path);
        }
    }

    public function testImageWithoutCursorAndOmittedXThrows(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $img = Image::fromBytes(TestImageFactory::pngRgb(2, 2));
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('no cursor set');
        $page->image($img, y: 10, w: 20, h: 20);
    }

    public function testImageWithoutCursorAndOmittedYThrows(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $img = Image::fromBytes(TestImageFactory::pngRgb(2, 2));
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('no cursor set');
        $page->image($img, x: 10, w: 20, h: 20);
    }

    public function testImageFallsBackToCursorWhenXOmitted(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setXY(40, 70);
        $img = Image::fromBytes(TestImageFactory::pngRgb(2, 2));
        $page->image($img, w: 100, h: 50);
        // CTM uses cursor (x=40, y=70): "100 0 0 -50 40 120 cm" (70 + 50).
        self::assertStringContainsString('100 0 0 -50 40 120 cm', $page->contentStream()->bytes());
    }

    public function testImageAdvancesCursorXToRightEdge(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setXY(10, 20);
        $img = Image::fromBytes(TestImageFactory::pngRgb(2, 2));
        $page->image($img, w: 100, h: 50);
        // x advances by effW (100), y is left alone.
        self::assertSame(110.0, $page->getX());
        self::assertSame(20.0, $page->getY());
    }

    public function testImageCursorAdvanceUsesAspectRatioWhenOnlyOneDimGiven(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setXY(0, 0);
        // 10x5 -> aspect 2:1. Pass h=50 -> effW = 100.
        $img = Image::fromBytes(TestImageFactory::pngRgb(width: 10, height: 5));
        $page->image($img, h: 50);
        self::assertSame(100.0, $page->getX());
    }

    public function testImageChainsWithCellViaSharedCursor(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->cell(x: 10, y: 20, w: 30, h: 30, text: '', padding: 0);
        // After cell with ln=RIGHT, cursor is at (40, 20).
        $img = Image::fromBytes(TestImageFactory::pngRgb(2, 2));
        $page->image($img, w: 25, h: 25);
        // CTM should anchor at (40, 20): "25 0 0 -25 40 45 cm".
        self::assertStringContainsString('25 0 0 -25 40 45 cm', $page->contentStream()->bytes());
        // Then cursor advances by 25 to x=65.
        self::assertSame(65.0, $page->getX());
    }

    public function testTextWithCustomFontEmitsHexString(): void
    {
        $path = __DIR__ . '/../Golden/fixtures/fonts/FreeSans.ttf';
        if (!is_file($path)) {
            self::markTestSkipped('FreeSans fixture not present');
        }
        $pdf = new \DragonOfMercy\PhpPdf\Document();
        $pdf->registerFontFamily('FS', regular: $path);
        $page = $pdf->addPage();
        $page->setFont(Font::custom('FS'), 14);
        $page->text(50, 50, 'AB');
        $bytes = $page->contentStream()->bytes();
        self::assertMatchesRegularExpression('/<[0-9A-F]{4,}> Tj/', $bytes);
    }

    public function testCustomFontWithoutRegistrationThrows(): void
    {
        $pdf = new \DragonOfMercy\PhpPdf\Document();
        $page = $pdf->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('registerFontFamily');
        $page->setFont(Font::custom('Unknown'), 14);
    }

    public function testStandardFontStillEmitsLiteralString(): void
    {
        $pdf = new \DragonOfMercy\PhpPdf\Document();
        $page = $pdf->addPage();
        $page->setFont(Font::helvetica(), 11);
        $page->text(50, 50, 'AB');
        $bytes = $page->contentStream()->bytes();
        self::assertStringContainsString('(AB) Tj', $bytes);
        self::assertDoesNotMatchRegularExpression('/<[0-9A-F]{4,}> Tj/', $bytes);
    }

    public function testStringWidthDispatchesToCustomFont(): void
    {
        $path = __DIR__ . '/../Golden/fixtures/fonts/FreeSans.ttf';
        if (!is_file($path)) {
            self::markTestSkipped('FreeSans fixture not present');
        }
        $pdf = new \DragonOfMercy\PhpPdf\Document();
        $pdf->registerFontFamily('FS', regular: $path);
        $page = $pdf->addPage();
        $page->setFont(Font::custom('FS'), 14);
        $w = $page->stringWidth('A');
        self::assertGreaterThan(0.0, $w);
    }

    public function testAutoWidthAddsOneMeasurementToTheCellPipelineWhenFitIsNone(): void
    {
        $metricsRegistry = new MetricsRegistry();
        $page = new Page(
            pageWidth: 595,
            pageHeight: 842,
            fontRegistry: new FontRegistry(),
            metricsRegistry: $metricsRegistry,
            imageRegistry: new ImageRegistry(),
        );
        $page->setFont(Font::helvetica(), 12);

        // Inject a counting engine so we can compare measurement counts between paths.
        $ref = new ReflectionProperty($page, 'currentFontEngine');
        $inner = $ref->getValue($page);
        if (!$inner instanceof FontEngine) {
            $inner = new StandardFontEngine(Font::helvetica(), $metricsRegistry->metricsFor(Font::helvetica()));
        }
        $counter = new MeasureCountingFontEngine($inner);
        $ref->setValue($page, $counter);

        // Explicit width: only the wrap pipeline measures.
        $page->setXY(10, 10);
        $page->cell(w: 100.0, text: 'Hello');
        $explicit = $counter->measureCalls;
        $counter->measureCalls = 0;

        // Auto-width: adds exactly one measurement for the widest-line scan.
        $page->setXY(10, 10);
        $page->cell(text: 'Hello');
        $auto = $counter->measureCalls;

        self::assertSame($explicit + 1, $auto);
    }

    public function testAutoWidthAddsZeroMeasurementsToTheCellPipelineWhenFitIsCondense(): void
    {
        $metricsRegistry = new MetricsRegistry();
        $page = new Page(
            pageWidth: 595,
            pageHeight: 842,
            fontRegistry: new FontRegistry(),
            metricsRegistry: $metricsRegistry,
            imageRegistry: new ImageRegistry(),
        );
        $page->setFont(Font::helvetica(), 12);

        $ref = new ReflectionProperty($page, 'currentFontEngine');
        $inner = $ref->getValue($page);
        if (!$inner instanceof FontEngine) {
            $inner = new StandardFontEngine(Font::helvetica(), $metricsRegistry->metricsFor(Font::helvetica()));
        }
        $counter = new MeasureCountingFontEngine($inner);
        $ref->setValue($page, $counter);

        // Explicit width: condenseText measures each paragraph once.
        $page->setXY(10, 10);
        $page->cell(w: 100.0, text: "Hello\nWorld", fit: Fit::CONDENSE);
        $explicit = $counter->measureCalls;
        $counter->measureCalls = 0;

        // Auto-width: no extra measurement (no widestLineWidth pass for CONDENSE).
        $page->setXY(10, 10);
        $page->cell(text: "Hello\nWorld", fit: Fit::CONDENSE);
        $auto = $counter->measureCalls;

        self::assertSame($explicit, $auto);
    }

    public function testAutoWidthAddsZeroMeasurementsToTheCellPipelineWhenFitIsShrink(): void
    {
        $metricsRegistry = new MetricsRegistry();
        $page = new Page(
            pageWidth: 595,
            pageHeight: 842,
            fontRegistry: new FontRegistry(),
            metricsRegistry: $metricsRegistry,
            imageRegistry: new ImageRegistry(),
        );
        $page->setFont(Font::helvetica(), 12);

        $ref = new ReflectionProperty($page, 'currentFontEngine');
        $inner = $ref->getValue($page);
        if (!$inner instanceof FontEngine) {
            $inner = new StandardFontEngine(Font::helvetica(), $metricsRegistry->metricsFor(Font::helvetica()));
        }
        $counter = new MeasureCountingFontEngine($inner);
        $ref->setValue($page, $counter);

        // Explicit width wide enough to avoid shrinking: shrinkText measures
        // each paragraph once (no second pass since effectiveSize == originalSize).
        $page->setXY(10, 10);
        $page->cell(w: 500.0, text: "Hello\nWorld", fit: Fit::SHRINK);
        $explicit = $counter->measureCalls;
        $counter->measureCalls = 0;

        // Auto-width: same count (no widestLineWidth pass for SHRINK).
        $page->setXY(10, 10);
        $page->cell(text: "Hello\nWorld", fit: Fit::SHRINK);
        $auto = $counter->measureCalls;

        self::assertSame($explicit, $auto);
    }

    public function testPageNumberReturnsAssignedValue(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        self::assertSame(1, $page->pageNumber());
    }

    public function testPageNumberThrowsWhenPageStandalone(): void
    {
        $page = new Page(
            pageWidth: 595,
            pageHeight: 842,
            fontRegistry: new FontRegistry(),
            metricsRegistry: new MetricsRegistry(),
            imageRegistry: new ImageRegistry(),
        );
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Page number is not set');
        $page->pageNumber();
    }

    public function testCellTriggersAutoBreakOnOverflow(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setMargins(PageMargins::all(20.0));
        $doc->setAutoPageBreak(true);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);

        // Page A4 portrait = 595 x 842 pt. Bottom limit = 842 - 20 = 822.
        // Place cursor at y=815: cell h=20 ends at 835 > 822 -> triggers auto-break.
        $page->setXY(20, 815);
        $result = $page->cell(w: 100, h: 20, text: 'Late');

        self::assertNotSame($page, $result->page, 'Auto-break should have produced a new page');
        self::assertSame(2, $result->page->pageNumber());
    }

    public function testCellDoesNotTriggerAutoBreakWhenDisabled(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setMargins(PageMargins::all(20.0));
        // autoPageBreak left off (default).
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $page->setXY(20, 815);
        $result = $page->cell(w: 100, h: 20, text: 'Late');

        self::assertSame($page, $result->page);
        self::assertSame(1, $result->page->pageNumber());
    }

    public function testCellResultPageReferencesSamePageWhenNoBreak(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setMargins(PageMargins::all(20.0));
        $doc->setAutoPageBreak(true);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        $result = $page->cell(w: 100, h: 10, text: 'Fits');
        self::assertSame($page, $result->page);
    }

    public function testCellInsideHeaderDoesNotRecurse(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setMargins(PageMargins::all(20.0));
        $doc->setAutoPageBreak(true);
        $doc->setHeader(function (Page $p): void {
            $p->setFont(Font::helvetica(), 12);
            // Even though this would overflow, the auto-break check must be
            // suppressed inside the header callback.
            $p->setXY(20, 815);
            $p->cell(w: 100, h: 20, text: 'Header content');
        });
        $page = $doc->addPage();
        self::assertSame(1, $page->pageNumber());
        self::assertSame(1, $doc->pageCount());
    }

    public function testCellLargerThanDrawableAreaDoesNotInfiniteLoop(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setMargins(PageMargins::all(20.0));
        $doc->setAutoPageBreak(true);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 12);
        // Drawable height = 842 - 20 - 20 = 802 pt. A cell with h=900 cannot fit
        // even on a fresh page. Auto-break must spawn exactly one new page and
        // emit the cell there (accepting visual overflow). NOT loop.
        $page->setXY(20, 815);
        $result = $page->cell(w: 100, h: 900, text: 'Giant');

        self::assertSame(2, $result->page->pageNumber());
        self::assertSame(2, $doc->pageCount());
    }

    public function testResolveDefaultBorderWidthPtFallsBackToDocumentMm(): void
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        // Document init = 0.25 mm = 0.708661417... pt
        self::assertEqualsWithDelta(
            Unit::MM->toPoints(0.25),
            $page->resolveDefaultBorderWidthPt(),
            1e-10,
        );
    }

    public function testResolveDefaultBorderWidthPtFallsBackToDocumentPt(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        self::assertSame(0.25, $page->resolveDefaultBorderWidthPt());
    }

    public function testPageOverrideTakesPrecedenceOverDocument(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setDefaultBorderWidth(1.0);
        $page = $doc->addPage();
        $page->setDefaultBorderWidth(2.0);
        self::assertSame(2.0, $page->resolveDefaultBorderWidthPt());
    }

    public function testPageNullResetsToDocumentFallback(): void
    {
        $doc = new Document(Unit::PT);
        $doc->setDefaultBorderWidth(1.0);
        $page = $doc->addPage();
        $page->setDefaultBorderWidth(2.0);
        $page->setDefaultBorderWidth(null);
        self::assertSame(1.0, $page->resolveDefaultBorderWidthPt());
    }

    public function testPageSetDefaultBorderWidthRejectsZero(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Default border width must be positive, got 0');
        $page->setDefaultBorderWidth(0.0);
    }

    public function testPageSetDefaultBorderWidthRejectsNegative(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Default border width must be positive, got -1');
        $page->setDefaultBorderWidth(-1.0);
    }

    public function testPageSetDefaultBorderWidthFluent(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        self::assertSame($page, $page->setDefaultBorderWidth(0.5));
    }

    public function testResolveDefaultBorderWidthPtThrowsWhenPageHasNoDocumentAndNoOverride(): void
    {
        $page = $this->page();
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Page has no Document and no per-page default border width was set');
        $page->resolveDefaultBorderWidthPt();
    }

    public function testPageOverrideWorksWithoutDocument(): void
    {
        $page = $this->page();
        $page->setDefaultBorderWidth(1.5);
        // page() uses pt by default (Page constructor unit default is Unit::PT)
        self::assertSame(1.5, $page->resolveDefaultBorderWidthPt());
    }

    public function testLinkAddsToInternalListAndReturnsSelfForChaining(): void
    {
        $page = $this->page();
        $link1 = \DragonOfMercy\PhpPdf\Outline\Link::url('https://a.example');
        $link2 = \DragonOfMercy\PhpPdf\Outline\Link::url('https://b.example');
        $ret = $page->link(10, 20, 30, 12, $link1)->link(10, 40, 30, 12, $link2);
        self::assertSame($page, $ret);
        $annots = $page->getLinkAnnotations();
        self::assertCount(2, $annots);
        self::assertSame('https://a.example', $annots[0]->link->url);
        self::assertSame('https://b.example', $annots[1]->link->url);
    }

    public function testLinkRejectsNonPositiveWidth(): void
    {
        $page = $this->page();
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $this->expectExceptionMessage('Link annotation width and height must be positive, got w=0 h=12');
        $page->link(10, 20, 0, 12, \DragonOfMercy\PhpPdf\Outline\Link::url('https://x'));
    }

    public function testLinkRejectsNonPositiveHeight(): void
    {
        $page = $this->page();
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $this->expectExceptionMessage('Link annotation width and height must be positive, got w=30 h=-1');
        $page->link(10, 20, 30, -1, \DragonOfMercy\PhpPdf\Outline\Link::url('https://x'));
    }

    public function testPageWithoutLinkHasEmptyAnnotationList(): void
    {
        $page = $this->page();
        self::assertSame([], $page->getLinkAnnotations());
    }

    public function testFieldStoresFormFieldAndReturnsSelf(): void
    {
        $page = (new \DragonOfMercy\PhpPdf\Document())->addPage();
        $field = new \DragonOfMercy\PhpPdf\Form\TextField(50.0, 100.0, 80.0, 8.0, name: 'a');
        $result = $page->field($field);
        self::assertSame($page, $result);
        self::assertSame([$field], $page->getFormFields());
    }

    public function testFieldChainsMultipleTypes(): void
    {
        $page = (new \DragonOfMercy\PhpPdf\Document())->addPage();
        $page->field(new \DragonOfMercy\PhpPdf\Form\TextField(50.0, 100.0, 80.0, 8.0, name: 't'))
            ->field(new \DragonOfMercy\PhpPdf\Form\Checkbox(50.0, 120.0, 5.0, 5.0, name: 'c'));
        self::assertCount(2, $page->getFormFields());
    }

    public function testPageWithoutFieldsReturnsEmptyList(): void
    {
        $page = (new \DragonOfMercy\PhpPdf\Document())->addPage();
        self::assertSame([], $page->getFormFields());
    }
}
