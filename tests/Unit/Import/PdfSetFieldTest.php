<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Import;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\ButtonAction;
use DragonOfMercy\PhpPdf\Form\Checkbox;
use DragonOfMercy\PhpPdf\Form\Listbox;
use DragonOfMercy\PhpPdf\Form\PushButton;
use DragonOfMercy\PhpPdf\Form\TextField;
use DragonOfMercy\PhpPdf\PdfEditor;
use PHPUnit\Framework\TestCase;

/**
 * Tests PdfEditor::setField() validation and PendingChanges::fieldEdits recording (Task 10).
 */
final class PdfSetFieldTest extends TestCase
{
    private static function buildTextFormPdf(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new TextField(20, 20, 80, 8, name: 'city'));
        return $doc->output();
    }

    private static function buildReadOnlyFormPdf(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new TextField(20, 20, 80, 8, name: 'locked', readOnly: true));
        return $doc->output();
    }

    private static function buildPushButtonFormPdf(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new PushButton(20, 20, 60, 8, name: 'submit', caption: 'Submit', action: ButtonAction::openUrl('https://example.com')));
        return $doc->output();
    }

    private static function buildCheckboxAndListboxPdf(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new Checkbox(20, 20, 5, 5, name: 'agree'));
        $page->field(new Listbox(20, 40, 80, 30, name: 'tags', options: ['a', 'b', 'c'], multiSelect: true));
        return $doc->output();
    }

    public function testSetFieldUnknownNameThrowsWithSuggestion(): void
    {
        $pdf = PdfEditor::fromBytes(self::buildTextFormPdf());

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/city/');
        $pdf->setField('citi', 'X');
    }

    public function testReadOnlyThrowsUnlessForced(): void
    {
        $pdf = PdfEditor::fromBytes(self::buildReadOnlyFormPdf());

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/read-only/i');
        $pdf->setField('locked', 'X');
    }

    public function testReadOnlyWithForcedDoesNotThrowAndReturnsSelf(): void
    {
        $pdf = PdfEditor::fromBytes(self::buildReadOnlyFormPdf());
        $result = $pdf->setField('locked', 'X', force: true);
        self::assertSame($pdf, $result);
    }

    public function testTypeMismatchThrows(): void
    {
        $pdf = PdfEditor::fromBytes(self::buildTextFormPdf());

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/expects a string/i');
        $pdf->setField('city', true);
    }

    public function testPushButtonRejected(): void
    {
        $pdf = PdfEditor::fromBytes(self::buildPushButtonFormPdf());

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/cannot be filled/i');
        $pdf->setField('submit', 'x');
    }

    public function testRecordsEditAndLastWriteWins(): void
    {
        $pdf = PdfEditor::fromBytes(self::buildTextFormPdf());

        $result = $pdf->setField('city', 'A')->setField('city', 'B');

        // Fluent: chain returns $this
        self::assertSame($pdf, $result);

        // Last-write-wins: fieldEdits is public on PendingChanges and
        // accessible via reflection here to confirm the value is 'B'
        $pendingProp = new \ReflectionProperty($pdf, 'pending');
        /** @var \DragonOfMercy\PhpPdf\Modify\PendingChanges $pending */
        $pending = $pendingProp->getValue($pdf);
        self::assertSame('B', $pending->fieldEdits['city']);
    }

    public function testNoFormThrowsNoFieldsMessage(): void
    {
        $doc = new Document();
        $doc->addPage();
        $pdf = PdfEditor::fromBytes($doc->output());

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/no AcroForm/i');
        $pdf->setField('anything', 'x');
    }

    public function testCheckboxAcceptsBoolAndReturnsFluentSelf(): void
    {
        $pdf = PdfEditor::fromBytes(self::buildCheckboxAndListboxPdf());
        $result = $pdf->setField('agree', true);
        self::assertSame($pdf, $result);
    }

    public function testCheckboxRejectsString(): void
    {
        $pdf = PdfEditor::fromBytes(self::buildCheckboxAndListboxPdf());

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/expects a bool/i');
        $pdf->setField('agree', 'x');
    }

    public function testListboxAcceptsArrayAndReturnsSelf(): void
    {
        $pdf = PdfEditor::fromBytes(self::buildCheckboxAndListboxPdf());
        $result = $pdf->setField('tags', ['a']);
        self::assertSame($pdf, $result);
    }

    public function testListboxAcceptsStringAndReturnsSelf(): void
    {
        $pdf = PdfEditor::fromBytes(self::buildCheckboxAndListboxPdf());
        $result = $pdf->setField('tags', 'a');
        self::assertSame($pdf, $result);
    }
}
