<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Fill;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\Fill\FieldValueDecoder;
use DragonOfMercy\PhpPdf\Form\Fill\FormFieldType;
use DragonOfMercy\PhpPdf\Form\Fill\ResolvedField;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\TextString;
use PHPUnit\Framework\TestCase;

final class FieldValueDecoderTest extends TestCase
{
    private static function reader(): PdfReader
    {
        $doc = new Document();
        $doc->addPage();
        return PdfReader::fromBytes($doc->output());
    }

    private static function field(FormFieldType $type, ?PdfObject $value): ResolvedField
    {
        $dict = Dictionary::empty();
        if ($value !== null) {
            $dict = $dict->withEntry(Name::of('V'), $value);
        }
        return new ResolvedField(1, $dict, [], 'fld', $type, 0, null, []);
    }

    public function testTextReturnsDecodedString(): void
    {
        $rf = self::field(FormFieldType::Text, TextString::of('hello'));
        self::assertSame('hello', FieldValueDecoder::decode($rf, self::reader()));
    }

    public function testTextAbsentReturnsNull(): void
    {
        $rf = self::field(FormFieldType::Text, null);
        self::assertNull(FieldValueDecoder::decode($rf, self::reader()));
    }

    public function testCheckboxOnReturnsTrue(): void
    {
        $rf = self::field(FormFieldType::Checkbox, Name::of('Yes'));
        self::assertTrue(FieldValueDecoder::decode($rf, self::reader()));
    }

    public function testCheckboxOffReturnsFalse(): void
    {
        $rf = self::field(FormFieldType::Checkbox, Name::of('Off'));
        self::assertFalse(FieldValueDecoder::decode($rf, self::reader()));
    }

    public function testCheckboxAbsentReturnsFalse(): void
    {
        $rf = self::field(FormFieldType::Checkbox, null);
        self::assertFalse(FieldValueDecoder::decode($rf, self::reader()));
    }

    public function testRadioReturnsExportName(): void
    {
        $rf = self::field(FormFieldType::Radio, Name::of('Choice1'));
        self::assertSame('Choice1', FieldValueDecoder::decode($rf, self::reader()));
    }

    public function testRadioOffReturnsNull(): void
    {
        $rf = self::field(FormFieldType::Radio, Name::of('Off'));
        self::assertNull(FieldValueDecoder::decode($rf, self::reader()));
    }

    public function testRadioAbsentReturnsNull(): void
    {
        $rf = self::field(FormFieldType::Radio, null);
        self::assertNull(FieldValueDecoder::decode($rf, self::reader()));
    }

    public function testListboxSingleReturnsString(): void
    {
        $rf = self::field(FormFieldType::Listbox, TextString::of('a'));
        self::assertSame('a', FieldValueDecoder::decode($rf, self::reader()));
    }

    public function testListboxMultiReturnsList(): void
    {
        $rf = self::field(FormFieldType::Listbox, PdfArray::of(TextString::of('a'), TextString::of('b')));
        self::assertSame(['a', 'b'], FieldValueDecoder::decode($rf, self::reader()));
    }

    public function testListboxEmptyArrayReturnsNull(): void
    {
        $rf = self::field(FormFieldType::Listbox, PdfArray::of());
        self::assertNull(FieldValueDecoder::decode($rf, self::reader()));
    }

    public function testPushButtonReturnsNull(): void
    {
        $rf = self::field(FormFieldType::PushButton, TextString::of('x'));
        self::assertNull(FieldValueDecoder::decode($rf, self::reader()));
    }
}
