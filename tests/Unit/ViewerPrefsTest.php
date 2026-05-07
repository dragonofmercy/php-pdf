<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\OpenAction;
use DragonOfMercy\PhpPdf\PageLayout;
use DragonOfMercy\PhpPdf\PageMode;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that setPageLayout / setPageMode / setOpenAction inject the right
 * entries into the PDF catalog and that OpenAction coordinates round-trip
 * correctly from the document unit (top-down) to PDF native (bottom-up, pt).
 */
final class ViewerPrefsTest extends TestCase
{
    public function testNoViewerPrefsByDefault(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $bytes = $doc->output();
        self::assertStringNotContainsString('/PageLayout', $bytes);
        self::assertStringNotContainsString('/PageMode', $bytes);
        self::assertStringNotContainsString('/OpenAction', $bytes);
    }

    public function testSetPageLayoutAddsCatalogEntry(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->setPageLayout(PageLayout::TWO_COLUMN_RIGHT);
        self::assertStringContainsString('/PageLayout /TwoColumnRight', $doc->output());
    }

    public function testSetPageModeAddsCatalogEntry(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->setPageMode(PageMode::USE_OUTLINES);
        self::assertStringContainsString('/PageMode /UseOutlines', $doc->output());
    }

    public function testSetPageLayoutNullClearsTheEntry(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->setPageLayout(PageLayout::ONE_COLUMN);
        $doc->setPageLayout(null);
        self::assertStringNotContainsString('/PageLayout', $doc->output());
    }

    public function testOpenActionFit(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->setOpenAction(OpenAction::fit());
        // [3 0 R /Fit] -- page is object 3 in the no-metadata path.
        self::assertStringContainsString('/OpenAction [3 0 R /Fit]', $doc->output());
    }

    public function testOpenActionFitWidthDefaultsToTopOfPage(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage(); // A4 portrait => height 841.889764 pt
        $doc->setOpenAction(OpenAction::fitWidth());
        // top defaults to "page top" => pageHeight in PDF coords.
        self::assertStringContainsString(
            '/OpenAction [3 0 R /FitH ' . self::pdfNumber($page->pageHeight) . ']',
            $doc->output(),
        );
    }

    public function testOpenActionFitWidthConvertsMmTopDown(): void
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage(); // A4 portrait, height ~841.89 pt
        // Top at 50 mm from page top.
        $doc->setOpenAction(OpenAction::fitWidth(top: 50));
        // pdf_top = pageHeight - 50mm_in_pt = 841.889764 - 141.732283 = 700.157481
        $expectedTopPt = $page->pageHeight - Unit::MM->toPoints(50);
        self::assertStringContainsString(
            '/OpenAction [3 0 R /FitH ' . self::pdfNumber($expectedTopPt) . ']',
            $doc->output(),
        );
    }

    public function testOpenActionZoomConvertsBothCoordinatesAndKeepsZoomVerbatim(): void
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $doc->setOpenAction(OpenAction::zoom(left: 10, top: 20, zoom: 1.5));
        $expectedLeftPt = Unit::MM->toPoints(10);
        $expectedTopPt = $page->pageHeight - Unit::MM->toPoints(20);
        self::assertStringContainsString(
            '/OpenAction [3 0 R /XYZ '
                . self::pdfNumber($expectedLeftPt) . ' '
                . self::pdfNumber($expectedTopPt) . ' 1.5]',
            $doc->output(),
        );
    }

    public function testOpenActionActualSizeAnchorsTopLeftAt100Percent(): void
    {
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $doc->setOpenAction(OpenAction::actualSize());
        // left=0, top=pageHeight, zoom=1
        self::assertStringContainsString(
            '/OpenAction [3 0 R /XYZ 0 ' . self::pdfNumber($page->pageHeight) . ' 1]',
            $doc->output(),
        );
    }

    public function testOpenActionTargetsTheRequestedPage(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->addPage();
        $doc->addPage();
        $doc->setOpenAction(OpenAction::fit(2));
        // 3 pages = objects 3, 4, 5 in the no-metadata path; page 2 = object 4.
        self::assertStringContainsString('/OpenAction [4 0 R /Fit]', $doc->output());
    }

    public function testOpenActionOnInvalidPageThrows(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->setOpenAction(OpenAction::fit(99));
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('OpenAction targets page 99 but document has 1 page');
        $doc->output();
    }

    public function testAllThreeViewerPrefsCoexistInCatalog(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->setPageLayout(PageLayout::ONE_COLUMN);
        $doc->setPageMode(PageMode::USE_THUMBS);
        $doc->setOpenAction(OpenAction::fit());
        $bytes = $doc->output();
        self::assertStringContainsString('/PageLayout /OneColumn', $bytes);
        self::assertStringContainsString('/PageMode /UseThumbs', $bytes);
        self::assertStringContainsString('/OpenAction [3 0 R /Fit]', $bytes);
    }

    public function testViewerPrefsApplyOnMetadataPath(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->metadata()->title = 'X';
        $doc->setPageLayout(PageLayout::SINGLE_PAGE);
        $doc->setOpenAction(OpenAction::fit());
        $bytes = $doc->output();
        // First page is object 5 when metadata is present.
        self::assertStringContainsString('/PageLayout /SinglePage', $bytes);
        self::assertStringContainsString('/OpenAction [5 0 R /Fit]', $bytes);
    }

    public function testViewerPrefsApplyOnEncryptedPath(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->encryption()->userPassword = 'u';
        $doc->encryption()->ownerPassword = 'o';
        $doc->encryption()->randomSource = static fn (int $n): string => str_repeat("\x00", $n);
        $doc->setPageMode(PageMode::FULL_SCREEN);
        $doc->setOpenAction(OpenAction::fit());
        $bytes = $doc->output();
        // First page is object 4 when encrypted without metadata.
        self::assertStringContainsString('/PageMode /FullScreen', $bytes);
        self::assertStringContainsString('/OpenAction [4 0 R /Fit]', $bytes);
    }

    /**
     * Mirrors PdfNumber::ofFloat()'s formatting (6 decimals, trailing zeros
     * trimmed) so assertions match the bytes the writer emits.
     */
    private static function pdfNumber(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
