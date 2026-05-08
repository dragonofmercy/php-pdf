<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\ImageRegistry;
use DragonOfMercy\PhpPdf\LineCap;
use DragonOfMercy\PhpPdf\LineJoin;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\Path;
use DragonOfMercy\PhpPdf\PathOperation;
use DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory;
use PHPUnit\Framework\TestCase;

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
        $page->cell(x: 0, y: 0, w: 100, text: '', border: \DragonOfMercy\PhpPdf\Border::all());
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

    public function testCellWithoutLnDoesNotMoveCursor(): void
    {
        $page = new Page(595, 842, new FontRegistry(), new MetricsRegistry(), new ImageRegistry());
        $page->setFont(Font::helvetica(), 12);
        $page->cell(x: 20, y: 30, w: 40, h: 30, text: 'A', padding: 0, ln: \DragonOfMercy\PhpPdf\NextPosition::RIGHT);
        // Second cell does NOT pass ln => cursor stays at (60, 30); third call still
        // resolves from that same cursor (no drift).
        $page->cell(w: 25, h: 30, text: 'B', padding: 0);
        $r = $page->cell(w: 25, h: 30, text: 'C', padding: 0);
        self::assertSame(85.0, $r->x);   // 20 + 40 + 25, same as previous call
        self::assertSame(60.0, $r->y);
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
}
