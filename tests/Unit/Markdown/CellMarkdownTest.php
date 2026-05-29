<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Markdown;

use DragonOfMercy\PhpPdf\{Document, Unit, Font, Border};
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class CellMarkdownTest extends TestCase
{
    public function testMarkdownCellAutoSizesAndReturnsResult(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);
        $r = $page->cell(x: 20, y: 20, w: 200, text: "# Hi\n\nBody text that wraps onto enough lines.", border: Border::all(), markdown: true);
        self::assertGreaterThan(0.0, $r->height);
    }

    public function testMarkdownCellDoesNotLeakFont(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(Font::times(), 14.0);
        $page->cell(x: 20, y: 20, w: 200, text: "**bold**", markdown: true);
        self::assertSame(Font::times()->pdfName(), $page->getFont()->pdfName());
        self::assertSame(14.0, $page->getFontSize());
    }

    public function testMarkdownCellRequiresWidth(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Markdown cell requires an explicit width (w)');
        $page->cell(x: 20, y: 20, text: "# Hi", markdown: true);
    }
}
