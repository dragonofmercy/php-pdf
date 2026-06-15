<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Modify\PageOperations;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\PdfEditor;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class PageOperationsEmitterTest extends TestCase
{
    private static function threePagePdf(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->addPage();
        $doc->addPage();
        return $doc->output();
    }

    public function testDeleteReducesPageCount(): void
    {
        $out = PdfEditor::fromBytes(self::threePagePdf())->deletePages(2)->output();
        $reader = PdfReader::fromBytes($out);
        self::assertSame(2, $reader->pageCount());
    }

    public function testReorderKeepsPageCount(): void
    {
        $out = PdfEditor::fromBytes(self::threePagePdf())->reorderPages([3, 1, 2])->output();
        $reader = PdfReader::fromBytes($out);
        self::assertSame(3, $reader->pageCount());
    }

    public function testDeleteAllThrowsAtOutput(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Cannot delete every page');
        PdfEditor::fromBytes(self::threePagePdf())->deletePages(1, 2, 3)->output();
    }

    public function testReorderOutOfRangeThrowsAtOutput(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('does not exist');
        PdfEditor::fromBytes(self::threePagePdf())->reorderPages([1, 2, 9])->output();
    }
}
