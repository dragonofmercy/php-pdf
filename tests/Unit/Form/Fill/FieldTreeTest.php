<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Fill;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\Checkbox;
use DragonOfMercy\PhpPdf\Form\Combobox;
use DragonOfMercy\PhpPdf\Form\Fill\FieldTree;
use DragonOfMercy\PhpPdf\Form\Fill\FormFieldType;
use DragonOfMercy\PhpPdf\Form\Listbox;
use DragonOfMercy\PhpPdf\Form\Radio;
use DragonOfMercy\PhpPdf\Form\TextField;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use PHPUnit\Framework\TestCase;

final class FieldTreeTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helper: build a PDF bytes from a Document with the given fields
    // -----------------------------------------------------------------------

    /** @param list<\DragonOfMercy\PhpPdf\Form\FormField> $fields */
    private static function buildPdf(array $fields): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        foreach ($fields as $field) {
            $page->field($field);
        }
        return $doc->output();
    }

    // -----------------------------------------------------------------------
    // Test 1: discovers text + checkbox, correct types and names
    // -----------------------------------------------------------------------

    public function testDiscoversTerminalFieldsWithTypesAndNames(): void
    {
        $bytes = self::buildPdf([
            new TextField(20, 20, 80, 8, name: 'city'),
            new Checkbox(20, 40, 5, 5, name: 'agree', checked: true),
        ]);

        $reader = PdfReader::fromBytes($bytes);
        $tree = new FieldTree($reader);
        $fields = $tree->terminalFields();

        self::assertCount(2, $fields);

        $byName = [];
        foreach ($fields as $f) {
            $byName[$f->name] = $f;
        }

        self::assertArrayHasKey('city', $byName);
        self::assertArrayHasKey('agree', $byName);

        self::assertSame(FormFieldType::Text, $byName['city']->type);
        self::assertSame(FormFieldType::Checkbox, $byName['agree']->type);
    }

    // -----------------------------------------------------------------------
    // Test 2: no AcroForm -> empty list
    // -----------------------------------------------------------------------

    public function testNoAcroFormYieldsEmpty(): void
    {
        $doc = new Document();
        $doc->addPage();
        $bytes = $doc->output();

        $reader = PdfReader::fromBytes($bytes);
        $tree = new FieldTree($reader);

        self::assertSame([], $tree->terminalFields());
    }

    // -----------------------------------------------------------------------
    // Test 3: Combobox and Radio group types and options
    // -----------------------------------------------------------------------

    public function testChoiceAndRadioTypesAndOptions(): void
    {
        $bytes = self::buildPdf([
            new Combobox(20, 20, 80, 8, name: 'country', options: ['fr' => 'France', 'ch' => 'Suisse']),
            new Radio(20, 40, 5, 5, group: 'gender', value: 'male', checked: true),
            new Radio(30, 40, 5, 5, group: 'gender', value: 'female'),
        ]);

        $reader = PdfReader::fromBytes($bytes);
        $tree = new FieldTree($reader);
        $fields = $tree->terminalFields();

        $byName = [];
        foreach ($fields as $f) {
            $byName[$f->name] = $f;
        }

        // Combobox type
        self::assertArrayHasKey('country', $byName);
        self::assertSame(FormFieldType::Combobox, $byName['country']->type);

        // Combobox options: export values (keys of the assoc array)
        $countryOptions = $byName['country']->options;
        sort($countryOptions);
        self::assertSame(['ch', 'fr'], $countryOptions);

        // Radio type
        self::assertArrayHasKey('gender', $byName);
        self::assertSame(FormFieldType::Radio, $byName['gender']->type);

        // Radio options: value names from /AP /N (excluding 'Off')
        $genderOptions = $byName['gender']->options;
        sort($genderOptions);
        self::assertSame(['female', 'male'], $genderOptions);
    }

    // -----------------------------------------------------------------------
    // Test 4: ReadOnly flag and Multiline flag are propagated
    // -----------------------------------------------------------------------

    public function testFlagsAreReported(): void
    {
        $bytes = self::buildPdf([
            new TextField(20, 20, 80, 8, name: 'notes', multiline: true, readOnly: true),
        ]);

        $reader = PdfReader::fromBytes($bytes);
        $tree = new FieldTree($reader);
        $fields = $tree->terminalFields();

        self::assertCount(1, $fields);
        $field = $fields[0];

        self::assertSame('notes', $field->name);
        self::assertTrue($field->isReadOnly());
        self::assertTrue($field->isMultiline());
    }

    // -----------------------------------------------------------------------
    // Test 5: Listbox type is detected correctly
    // -----------------------------------------------------------------------

    public function testListboxTypeDetected(): void
    {
        $bytes = self::buildPdf([
            new Listbox(20, 20, 80, 30, name: 'items', options: ['alpha', 'beta', 'gamma']),
        ]);

        $reader = PdfReader::fromBytes($bytes);
        $tree = new FieldTree($reader);
        $fields = $tree->terminalFields();

        self::assertCount(1, $fields);
        self::assertSame(FormFieldType::Listbox, $fields[0]->type);

        $opts = $fields[0]->options;
        sort($opts);
        self::assertSame(['alpha', 'beta', 'gamma'], $opts);
    }
}
