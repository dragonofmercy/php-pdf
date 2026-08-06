<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\Checkbox;
use DragonOfMercy\PhpPdf\Form\Combobox;
use DragonOfMercy\PhpPdf\Form\Listbox;
use DragonOfMercy\PhpPdf\Form\Radio;
use DragonOfMercy\PhpPdf\Form\TextField;
use DragonOfMercy\PhpPdf\PdfEditor;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for AcroForm field filling via PdfEditor::fromBytes() + setField().
 * The source form is built deterministically in-test (no stored asset) and the
 * filled output is byte-compared against a committed fixture.
 */
final class FormFillTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/form/fill/fill-all-types.pdf';

    /** Builds a source form Document containing one of each fillable field type. */
    public static function buildSourceBytes(): string
    {
        $doc = new Document();
        $page = $doc->addPage();

        // Single-line text field.
        $page->field(new TextField(20.0, 20.0, 80.0, 8.0, name: 'name'));

        // Multi-line text field.
        $page->field(new TextField(20.0, 35.0, 80.0, 24.0, name: 'bio', multiline: true));

        // Checkbox.
        $page->field(new Checkbox(20.0, 65.0, 5.0, 5.0, name: 'agree'));

        // Radio group of 2.
        $page->field(new Radio(20.0, 78.0, 5.0, 5.0, group: 'gender', value: 'male'));
        $page->field(new Radio(35.0, 78.0, 5.0, 5.0, group: 'gender', value: 'female'));

        // Combobox (export => display map).
        $page->field(new Combobox(20.0, 92.0, 80.0, 8.0, name: 'country', options: ['fr' => 'France', 'ch' => 'Suisse', 'de' => 'Germany']));

        // Multi-select listbox.
        $page->field(new Listbox(20.0, 107.0, 80.0, 24.0, name: 'interests', options: ['a' => 'Alpha', 'b' => 'Beta', 'c' => 'Gamma'], multiSelect: true));

        return $doc->output();
    }

    /** Builds the filled PDF (source + one incremental revision with filled values). */
    public static function buildFilledPdf(): string
    {
        $sourceBytes = self::buildSourceBytes();
        $pdf = PdfEditor::fromBytes($sourceBytes);
        $pdf->setField('name', 'Hello');
        $pdf->setField('bio', 'Line one of a long wrapping string that spans multiple lines in the multiline field.');
        $pdf->setField('agree', true);
        $pdf->setField('gender', 'female');
        $pdf->setField('country', 'ch');
        $pdf->setField('interests', ['a', 'c']);
        return $pdf->output();
    }

    public function testFillAllTypesMatchesFixtureBytes(): void
    {
        $actual = self::buildFilledPdf();
        $expected = file_get_contents(self::FIXTURE);
        self::assertNotFalse($expected, 'fixture missing - run tests/Golden/regenerate.php');
        self::assertSame($expected, $actual, 'rendered bytes diverge from fixture');
    }

    public function testQpdfCheck(): void
    {
        Qpdf::assertCheck(self::FIXTURE);
    }

    public function testFilledValuesPresent(): void
    {
        $bytes = self::buildFilledPdf();
        // The incremental revision sets NeedAppearances false (generated AP trusted).
        self::assertStringContainsString('/NeedAppearances false', $bytes);
        // Checkbox is set to On state.
        self::assertStringContainsString('/V /On', $bytes);
        self::assertStringContainsString('/AS /On', $bytes);
        // Radio is set to female.
        self::assertStringContainsString('/V /female', $bytes);
    }

    public function testDeterminism(): void
    {
        $a = self::buildFilledPdf();
        $b = self::buildFilledPdf();
        self::assertSame($a, $b);
    }
}
