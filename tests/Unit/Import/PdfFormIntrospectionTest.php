<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Import;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\Checkbox;
use DragonOfMercy\PhpPdf\Form\Combobox;
use DragonOfMercy\PhpPdf\Form\Fill\FormFieldType;
use DragonOfMercy\PhpPdf\Form\Listbox;
use DragonOfMercy\PhpPdf\Form\TextField;
use DragonOfMercy\PhpPdf\Pdf;
use PHPUnit\Framework\TestCase;

/**
 * Tests Pdf::formFields() and Pdf::field() introspection (Task 3).
 */
final class PdfFormIntrospectionTest extends TestCase
{
    private static function buildFormPdf(): string
    {
        $doc = new Document();
        $page = $doc->addPage();

        $page->field(new TextField(20, 20, 80, 8, name: 'city', value: 'Paris'));
        $page->field(new Checkbox(20, 40, 5, 5, name: 'agree', checked: false));

        return $doc->output();
    }

    private static function buildChoiceFormPdf(): string
    {
        $doc = new Document();
        $page = $doc->addPage();

        $page->field(new Combobox(20, 20, 80, 8, name: 'country', options: ['fr' => 'France', 'ch' => 'Suisse'], value: 'fr'));
        $page->field(new Listbox(20, 40, 80, 30, name: 'tags', options: ['a', 'b', 'c'], value: ['a', 'c'], multiSelect: true));

        return $doc->output();
    }

    public function testFormFieldsExposesNamesTypesValues(): void
    {
        $pdf = Pdf::fromBytes(self::buildFormPdf());
        $fields = $pdf->formFields();

        self::assertNotEmpty($fields);

        $byName = [];
        foreach ($fields as $f) {
            $byName[$f->name] = $f;
        }

        self::assertArrayHasKey('city', $byName);
        self::assertSame(FormFieldType::Text, $byName['city']->type);
        self::assertSame('Paris', $byName['city']->value);

        self::assertArrayHasKey('agree', $byName);
        self::assertSame(FormFieldType::Checkbox, $byName['agree']->type);
        self::assertFalse($byName['agree']->value);
    }

    public function testComboboxAndListboxCurrentValues(): void
    {
        $pdf = Pdf::fromBytes(self::buildChoiceFormPdf());
        $fields = $pdf->formFields();

        $byName = [];
        foreach ($fields as $f) {
            $byName[$f->name] = $f;
        }

        self::assertArrayHasKey('country', $byName);
        self::assertSame(FormFieldType::Combobox, $byName['country']->type);
        // /V is a PdfString with the export key 'fr'
        self::assertSame('fr', $byName['country']->value);

        self::assertArrayHasKey('tags', $byName);
        self::assertSame(FormFieldType::Listbox, $byName['tags']->type);
        // multiSelect -> /V is a PdfArray of PdfStrings
        self::assertIsArray($byName['tags']->value);
        self::assertContains('a', $byName['tags']->value);
        self::assertContains('c', $byName['tags']->value);
    }

    public function testFieldLookupAndAbsent(): void
    {
        $pdf = Pdf::fromBytes(self::buildFormPdf());

        $found = $pdf->field('city');
        self::assertNotNull($found);
        self::assertSame('city', $found->name);

        self::assertNull($pdf->field('nope'));
    }

    public function testNoFormReturnsEmpty(): void
    {
        $doc = new Document();
        $doc->addPage();
        $pdf = Pdf::fromBytes($doc->output());

        self::assertSame([], $pdf->formFields());
    }
}
