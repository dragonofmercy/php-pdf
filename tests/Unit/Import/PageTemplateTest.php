<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Import;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class PageTemplateTest extends TestCase
{
    private static function sourcePdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->rect(10, 10, 50, 30)->stroke();
        return $doc->output();
    }

    public function testTemplateEmitsScaledDoOperator(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $template = $doc->importPdfBytes(self::sourcePdfBytes())->page(1);

        $page->template($template, x: 100, y: 50, width: 297.64); // half A4 width

        $content = $page->contentStream()->bytes();
        self::assertStringContainsString('/Tpl1 Do', $content);
        // scale = 297.64 / 595.2756 = 0.500004 on both axes (ratio preserved);
        // cm under the page's global Y-flip: [sx 0 0 -sy x y+effH]
        self::assertStringContainsString('0.500004 0 0 -0.500004 100 470.948 cm', $content);
        self::assertSame(['Tpl1'], $page->templatesUsed());
    }

    public function testNaturalSizeAndStretch(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $template = $doc->importPdfBytes(self::sourcePdfBytes())->page(1);

        $page->template($template, x: 0, y: 0); // natural size: scale 1
        self::assertStringContainsString('1 0 0 -1 0 841.889764 cm', $page->contentStream()->bytes());

        $page->template($template, x: 0, y: 0, width: 100, height: 100); // stretch
        self::assertStringContainsString('0.167989 0 0 -0.11878 0 100 cm', $page->contentStream()->bytes());
    }

    public function testSameTemplateOnTwoPagesSharesOneShortName(): void
    {
        $doc = new Document(Unit::PT);
        $template = $doc->importPdfBytes(self::sourcePdfBytes())->page(1);
        $page1 = $doc->addPage();
        $page2 = $doc->addPage();
        $page1->template($template, 0, 0);
        $page2->template($template, 0, 0);
        self::assertSame(['Tpl1'], $page1->templatesUsed());
        self::assertSame(['Tpl1'], $page2->templatesUsed());
    }

    public function testTwoTemplatesGetDistinctNames(): void
    {
        $doc = new Document(Unit::PT);
        $source = $doc->importPdfBytes(self::sourcePdfBytes());
        $other = $doc->importPdfBytes(self::sourcePdfBytes());
        $page = $doc->addPage();
        $page->template($source->page(1), 0, 0);
        $page->template($other->page(1), 0, 0);
        self::assertSame(['Tpl1', 'Tpl2'], $page->templatesUsed());
    }

    public function testCursorDoesNotMove(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $template = $doc->importPdfBytes(self::sourcePdfBytes())->page(1);
        $xBefore = $page->getX();
        $yBefore = $page->getY();
        $page->template($template, 10, 10, 100);
        self::assertSame($xBefore, $page->getX());
        self::assertSame($yBefore, $page->getY());
    }

    public function testTaggingWrapsTemplateAsArtifact(): void
    {
        $doc = new Document(Unit::PT);
        $doc->enableTagging('en-US');
        $page = $doc->addPage();
        $template = $doc->importPdfBytes(self::sourcePdfBytes())->page(1);
        $page->template($template, 0, 0);
        $content = $page->contentStream()->bytes();
        $artifactAt = strpos($content, '/Artifact BMC');
        $doAt = strpos($content, '/Tpl1 Do');
        $emcAt = strpos($content, 'EMC', $doAt !== false ? $doAt : 0);
        self::assertIsInt($artifactAt);
        self::assertIsInt($doAt);
        self::assertIsInt($emcAt);
        self::assertLessThan($doAt, $artifactAt);
    }

    public function testUnitsAreConverted(): void
    {
        $doc = new Document(); // MM
        $page = $doc->addPage();
        $template = $doc->importPdfBytes(self::sourcePdfBytes())->page(1);
        $page->template($template, x: 0, y: 0, width: 105); // 105 mm = 297.6378 pt
        $content = $page->contentStream()->bytes();
        self::assertStringContainsString('/Tpl1 Do', $content);
        self::assertStringContainsString('0.5 0 0 -0.5 0 ', $content); // 105mm is exactly half of A4 210mm width
    }

    public function testNonPositiveWidthThrows(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $template = $doc->importPdfBytes(self::sourcePdfBytes())->page(1);
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Template width must be positive, got -1');
        $page->template($template, x: 0, y: 0, width: -1);
    }

    public function testNonPositiveHeightThrows(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $template = $doc->importPdfBytes(self::sourcePdfBytes())->page(1);
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Template height must be positive, got 0');
        $page->template($template, x: 0, y: 0, height: 0);
    }
}
