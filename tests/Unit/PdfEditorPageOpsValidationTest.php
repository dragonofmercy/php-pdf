<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\PdfEditor;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class PdfEditorPageOpsValidationTest extends TestCase
{
    private static function threePagePdf(): string
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->addPage();
        $doc->addPage();
        return $doc->output();
    }

    public function testDeletePagesRejectsZero(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Page number must be >= 1, got 0');
        PdfEditor::fromBytes(self::threePagePdf())->deletePages(0);
    }

    public function testDeletePagesRejectsDuplicates(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Duplicate page number 2');
        PdfEditor::fromBytes(self::threePagePdf())->deletePages(2, 2);
    }

    public function testReorderRejectsEmpty(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('reorderPages requires at least one page number');
        PdfEditor::fromBytes(self::threePagePdf())->reorderPages([]);
    }

    public function testReorderRejectsNonPositive(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Page number must be >= 1, got -1');
        PdfEditor::fromBytes(self::threePagePdf())->reorderPages([1, -1, 2]);
    }

    public function testValidCallsAreChainable(): void
    {
        $editor = PdfEditor::fromBytes(self::threePagePdf());
        self::assertSame($editor, $editor->deletePages(2));
        self::assertSame($editor, $editor->reorderPages([1, 3]));
    }
}
