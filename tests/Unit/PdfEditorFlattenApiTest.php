<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\TextField;
use DragonOfMercy\PhpPdf\PdfEditor;
use PHPUnit\Framework\TestCase;

final class PdfEditorFlattenApiTest extends TestCase
{
    private static function formBytes(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new TextField(20.0, 20.0, 80.0, 8.0, name: 'name'));
        return $doc->output();
    }

    public function testFlattenAllIsChainable(): void
    {
        $editor = PdfEditor::fromBytes(self::formBytes());
        self::assertSame($editor, $editor->flattenFields());
    }

    public function testUnknownNamedFieldThrowsWithHint(): void
    {
        $editor = PdfEditor::fromBytes(self::formBytes());
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/Unknown form field .nam3./');
        $editor->flattenFields(['nam3']);
    }

    public function testNamedExistingFieldIsAccepted(): void
    {
        $editor = PdfEditor::fromBytes(self::formBytes());
        self::assertSame($editor, $editor->flattenFields(['name']));
    }
}
