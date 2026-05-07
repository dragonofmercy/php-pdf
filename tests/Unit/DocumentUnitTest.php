<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Orientation;
use DragonOfMercy\PhpPdf\PageFormat;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class DocumentUnitTest extends TestCase
{
    public function testDefaultUnitIsMm(): void
    {
        $doc = new Document();
        self::assertSame(Unit::MM, $doc->unit);
    }

    public function testDefaultPageIsA4Portrait(): void
    {
        $page = (new Document())->addPage();
        self::assertEqualsWithDelta(595.275591, $page->pageWidth, 1e-4);
        self::assertEqualsWithDelta(841.889764, $page->pageHeight, 1e-4);
        self::assertSame(Unit::MM, $page->unit);
    }

    public function testLandscapeSwapsDimensions(): void
    {
        $page = (new Document())->addPage(PageFormat::A4, Orientation::LANDSCAPE);
        self::assertEqualsWithDelta(841.889764, $page->pageWidth, 1e-4);
        self::assertEqualsWithDelta(595.275591, $page->pageHeight, 1e-4);
    }

    public function testA5Portrait(): void
    {
        $page = (new Document())->addPage(PageFormat::A5);
        self::assertEqualsWithDelta(419.527559, $page->pageWidth, 1e-4);
        self::assertEqualsWithDelta(595.275591, $page->pageHeight, 1e-4);
    }

    public function testCustomFormatViaArray(): void
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage([100.0, 50.0]);
        self::assertEqualsWithDelta(283.464567, $page->pageWidth, 1e-4);
        self::assertEqualsWithDelta(141.732283, $page->pageHeight, 1e-4);
    }

    public function testCustomFormatInPt(): void
    {
        $page = (new Document(Unit::PT))->addPage([200, 100]);
        self::assertSame(200.0, $page->pageWidth);
        self::assertSame(100.0, $page->pageHeight);
    }

    public function testSubsequentAddPageReusesLastFormat(): void
    {
        $doc = new Document();
        $first = $doc->addPage(PageFormat::A6);
        $second = $doc->addPage();
        self::assertSame($first->pageWidth, $second->pageWidth);
        self::assertSame($first->pageHeight, $second->pageHeight);
    }

    public function testSubsequentAddPageReusesLastOrientation(): void
    {
        $doc = new Document();
        $first = $doc->addPage(PageFormat::A4, Orientation::LANDSCAPE);
        $second = $doc->addPage();
        self::assertSame($first->pageWidth, $second->pageWidth);
        self::assertSame($first->pageHeight, $second->pageHeight);
    }

    public function testSubsequentAddPageReusesLastCustom(): void
    {
        $doc = new Document();
        $first = $doc->addPage([99.0, 38.0]);
        $second = $doc->addPage();
        self::assertSame($first->pageWidth, $second->pageWidth);
        self::assertSame($first->pageHeight, $second->pageHeight);
    }

    public function testSwitchingFromCustomToStandardClearsCustom(): void
    {
        $doc = new Document();
        $doc->addPage([99.0, 38.0]);
        $a4 = $doc->addPage(PageFormat::A4);
        self::assertEqualsWithDelta(595.275591, $a4->pageWidth, 1e-4);
        // And subsequent addPage() should now reuse A4, not the custom value.
        $next = $doc->addPage();
        self::assertEqualsWithDelta(595.275591, $next->pageWidth, 1e-4);
    }

    public function testCustomIgnoresOrientation(): void
    {
        $doc = new Document();
        $page = $doc->addPage([100.0, 50.0], Orientation::LANDSCAPE);
        // Dimensions taken verbatim; orientation does not flip them.
        self::assertEqualsWithDelta(283.464567, $page->pageWidth, 1e-4);
        self::assertEqualsWithDelta(141.732283, $page->pageHeight, 1e-4);
    }

    public function testOrientationOnlyAppliesToLastFormat(): void
    {
        $doc = new Document();
        $doc->addPage(PageFormat::A4);
        $landscape = $doc->addPage(orientation: Orientation::LANDSCAPE);
        self::assertEqualsWithDelta(841.889764, $landscape->pageWidth, 1e-4);
    }

    public function testCustomRejectsNonPositiveWidth(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Page width must be positive');
        (new Document())->addPage([0, 100]);
    }

    public function testCustomRejectsNonPositiveHeight(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Page height must be positive');
        (new Document())->addPage([100, -5]);
    }

    public function testCustomRejectsWrongShape(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Custom page format must be [width, height]');
        (new Document())->addPage([100, 50, 25]);
    }

    public function testCustomRejectsAssociativeArray(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Custom page format must be [width, height]');
        // @phpstan-ignore-next-line argument.type
        (new Document())->addPage(['w' => 100, 'h' => 50]);
    }

    public function testCustomRejectsNonNumericValues(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('numeric');
        // @phpstan-ignore-next-line argument.type
        (new Document())->addPage(['100', '50']);
    }

    public function testMediaBoxReflectsCustomDimensions(): void
    {
        $doc = new Document(Unit::MM);
        $doc->addPage([105.0, 74.0]); // A7-ish business card
        $bytes = $doc->output();
        // 105 mm = 297.637795 pt, 74 mm = 209.76378 pt
        self::assertStringContainsString('/MediaBox [0 0 297.637795 209.76378]', $bytes);
    }
}
