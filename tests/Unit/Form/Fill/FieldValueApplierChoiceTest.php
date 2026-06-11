<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Fill;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Form\Combobox;
use DragonOfMercy\PhpPdf\Form\Fill\FieldTree;
use DragonOfMercy\PhpPdf\Form\Fill\FieldValueApplier;
use DragonOfMercy\PhpPdf\Form\Fill\ResolvedField;
use DragonOfMercy\PhpPdf\Form\Listbox;
use DragonOfMercy\PhpPdf\Reader\DictReader;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\CompressedStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use PHPUnit\Framework\TestCase;

final class FieldValueApplierChoiceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function buildPdfWithCombobox(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new Combobox(20, 80, 60, 8, name: 'country', options: ['fr' => 'France', 'ch' => 'Suisse', 'be' => 'Belgique']));
        return $doc->output();
    }

    private static function buildPdfWithMultiListbox(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new Listbox(20, 100, 60, 30, name: 'tags', options: ['a', 'b', 'c'], multiSelect: true));
        return $doc->output();
    }

    private static function buildPdfWithSingleListbox(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new Listbox(20, 100, 60, 30, name: 'single', options: ['a', 'b', 'c'], multiSelect: false));
        return $doc->output();
    }

    private static function buildPdfWithPairListbox(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new Listbox(20, 100, 60, 30, name: 'langs', options: ['fr' => 'Francais', 'en' => 'English'], multiSelect: true));
        return $doc->output();
    }

    private static function resolveField(PdfReader $reader, string $name): ResolvedField
    {
        foreach ((new FieldTree($reader))->terminalFields() as $f) {
            if ($f->name === $name) {
                return $f;
            }
        }
        self::fail("Field \"{$name}\" not found in PDF");
    }

    private static function makeAllocator(): \Closure
    {
        $next = 9000;
        return static function () use (&$next): int { return $next++; };
    }

    /**
     * @param list<IndirectObject> $objects
     */
    private static function dictForObjectNumber(array $objects, int $objectNumber): ?Dictionary
    {
        foreach ($objects as $obj) {
            if ($obj->objectNumber === $objectNumber) {
                $payload = $obj->payload();
                if ($payload instanceof Dictionary) {
                    return $payload;
                }
            }
        }
        return null;
    }

    /**
     * Returns the raw (uncompressed) content of the first Form XObject found,
     * or null if none exists.
     *
     * @param list<IndirectObject> $objects
     */
    private static function formXObjectContent(array $objects): ?string
    {
        foreach ($objects as $obj) {
            $payload = $obj->payload();
            if (!$payload instanceof CompressedStream) {
                continue;
            }
            $streamDict = $payload->streamDict();
            $subtypeRaw = $streamDict->get(Name::of('Subtype'));
            if (!$subtypeRaw instanceof Name || $subtypeRaw->value() !== 'Form') {
                continue;
            }
            $decompressed = gzuncompress($payload->compressedContent());
            if ($decompressed === false) {
                return null;
            }
            return $decompressed;
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Test 1: testComboboxSetsExportValueAndAppearance
    // apply('ch') -> /V == 'ch' (export), appearance content has '(Suisse) Tj'
    // -------------------------------------------------------------------------

    public function testComboboxSetsExportValueAndAppearance(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdfWithCombobox());
        $rf = self::resolveField($reader, 'country');

        $applied = (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, 'ch', self::makeAllocator());

        self::assertNotEmpty($applied->objects);

        // The field/widget dict must carry /V = 'ch' (export value)
        $fieldDict = self::dictForObjectNumber($applied->objects, $rf->objectNumber);
        self::assertNotNull($fieldDict, 'Re-emitted combobox object must be a Dictionary');

        $vDecoded = DictReader::decodeText($fieldDict->get(Name::of('V')));
        self::assertSame('ch', $vDecoded, '/V must be the export value "ch"');

        // /AP must be present
        $apRaw = $fieldDict->get(Name::of('AP'));
        self::assertNotNull($apRaw, '/AP must be present on the re-emitted combobox dict');

        // A Form XObject must be in the returned objects and its content must show the display text
        $content = self::formXObjectContent($applied->objects);
        self::assertNotNull($content, 'A Form XObject must be present among applied objects');
        self::assertStringContainsString('(Suisse) Tj', $content, 'Appearance stream must show display text "Suisse"');
    }

    // -------------------------------------------------------------------------
    // Test 2: testComboboxInvalidThrows
    // apply('xx') -> PdfException
    // -------------------------------------------------------------------------

    public function testComboboxInvalidThrows(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdfWithCombobox());
        $rf = self::resolveField($reader, 'country');

        $this->expectException(PdfException::class);

        (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, 'xx', self::makeAllocator());
    }

    // -------------------------------------------------------------------------
    // Test 3: testListboxMultiSetsValueArrayAndIndices
    // apply(['a','c']) -> /V = PdfArray of TextStrings, /I = [0,2], appearance
    // has highlight re+f sequences
    // -------------------------------------------------------------------------

    public function testListboxMultiSetsValueArrayAndIndices(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdfWithMultiListbox());
        $rf = self::resolveField($reader, 'tags');

        $applied = (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, ['a', 'c'], self::makeAllocator());

        self::assertNotEmpty($applied->objects);

        $fieldDict = self::dictForObjectNumber($applied->objects, $rf->objectNumber);
        self::assertNotNull($fieldDict, 'Re-emitted listbox object must be a Dictionary');

        // /V must be a PdfArray of two TextStrings
        $vRaw = $fieldDict->get(Name::of('V'));
        self::assertInstanceOf(PdfArray::class, $vRaw, '/V must be a PdfArray for multi-selection');
        /** @var PdfArray $vRaw */
        $elements = $vRaw->elements();
        self::assertCount(2, $elements, '/V array must have 2 elements');

        $v0 = DictReader::decodeText($elements[0]);
        $v1 = DictReader::decodeText($elements[1]);
        self::assertSame('a', $v0, '/V[0] must be "a"');
        self::assertSame('c', $v1, '/V[1] must be "c"');

        // /I must be [0, 2]
        $iRaw = $fieldDict->get(Name::of('I'));
        self::assertInstanceOf(PdfArray::class, $iRaw, '/I must be a PdfArray');
        /** @var PdfArray $iRaw */
        $iElements = $iRaw->elements();
        self::assertCount(2, $iElements, '/I must have 2 entries');
        self::assertInstanceOf(PdfNumber::class, $iElements[0]);
        self::assertInstanceOf(PdfNumber::class, $iElements[1]);
        /** @var PdfNumber $i0 */
        $i0 = $iElements[0];
        /** @var PdfNumber $i1 */
        $i1 = $iElements[1];
        self::assertSame(0, (int) $i0->value(), '/I[0] must be 0');
        self::assertSame(2, (int) $i1->value(), '/I[1] must be 2');

        // Appearance must be present with at least one highlight re+f
        $content = self::formXObjectContent($applied->objects);
        self::assertNotNull($content, 'A Form XObject must be present');
        self::assertStringContainsString(' re', $content, 'Listbox appearance must contain a rect "re" operator');
        self::assertStringContainsString(' f', $content, 'Listbox appearance must contain a fill "f" operator');
    }

    // -------------------------------------------------------------------------
    // Test 4: testListboxSingleValue
    // apply('b') on multi-select listbox -> /V = scalar TextString 'b'; /I = [1]
    // -------------------------------------------------------------------------

    public function testListboxSingleValue(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdfWithMultiListbox());
        $rf = self::resolveField($reader, 'tags');

        $applied = (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, 'b', self::makeAllocator());

        self::assertNotEmpty($applied->objects);

        $fieldDict = self::dictForObjectNumber($applied->objects, $rf->objectNumber);
        self::assertNotNull($fieldDict, 'Re-emitted listbox object must be a Dictionary');

        // /V must be a scalar TextString 'b'
        $vRaw = $fieldDict->get(Name::of('V'));
        self::assertNotInstanceOf(PdfArray::class, $vRaw, '/V must be a scalar TextString for single value');
        $vDecoded = DictReader::decodeText($vRaw);
        self::assertSame('b', $vDecoded, '/V must decode to "b"');

        // /I must be [1]
        $iRaw = $fieldDict->get(Name::of('I'));
        self::assertInstanceOf(PdfArray::class, $iRaw, '/I must be a PdfArray');
        /** @var PdfArray $iRaw */
        $iElements = $iRaw->elements();
        self::assertCount(1, $iElements, '/I must have 1 entry');
        self::assertInstanceOf(PdfNumber::class, $iElements[0]);
        /** @var PdfNumber $i0 */
        $i0 = $iElements[0];
        self::assertSame(1, (int) $i0->value(), '/I[0] must be 1 for option "b"');
    }

    // -------------------------------------------------------------------------
    // Test 5: testListboxArrayOnSingleSelectThrows
    // Non-multi listbox + array value -> PdfException
    // -------------------------------------------------------------------------

    public function testListboxArrayOnSingleSelectThrows(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdfWithSingleListbox());
        $rf = self::resolveField($reader, 'single');

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/single-select/i');

        (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, ['a', 'b'], self::makeAllocator());
    }

    // -------------------------------------------------------------------------
    // Test 6: testComboboxNonStringThrows
    // apply(123) (int is not accepted by the signature, use true/false/[]) -> PdfException
    // -------------------------------------------------------------------------

    public function testComboboxNonStringThrows(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdfWithCombobox());
        $rf = self::resolveField($reader, 'country');

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/combobox/i');

        // bool triggers the non-string path in applyCombobox
        (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, true, self::makeAllocator());
    }

    // -------------------------------------------------------------------------
    // Test 7: testListboxPairOptionsExportVsDisplay
    // Listbox with [export => display] pairs: /V stores export, appearance shows display.
    // -------------------------------------------------------------------------

    public function testListboxPairOptionsExportVsDisplay(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdfWithPairListbox());
        $rf = self::resolveField($reader, 'langs');

        $applied = (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, ['fr', 'en'], self::makeAllocator());

        self::assertNotEmpty($applied->objects);

        $fieldDict = self::dictForObjectNumber($applied->objects, $rf->objectNumber);
        self::assertNotNull($fieldDict, 'Re-emitted listbox object must be a Dictionary');

        // /V must be a PdfArray containing the EXPORT values, not the display strings
        $vRaw = $fieldDict->get(Name::of('V'));
        self::assertInstanceOf(PdfArray::class, $vRaw, '/V must be a PdfArray for multi-selection');
        /** @var PdfArray $vRaw */
        $vElements = $vRaw->elements();
        self::assertCount(2, $vElements, '/V array must have 2 elements');
        $v0 = DictReader::decodeText($vElements[0]);
        $v1 = DictReader::decodeText($vElements[1]);
        self::assertSame('fr', $v0, '/V[0] must be the export value "fr"');
        self::assertSame('en', $v1, '/V[1] must be the export value "en"');

        // /I must resolve to indices 0 and 1
        $iRaw = $fieldDict->get(Name::of('I'));
        self::assertInstanceOf(PdfArray::class, $iRaw, '/I must be a PdfArray');
        /** @var PdfArray $iRaw */
        $iElements = $iRaw->elements();
        self::assertCount(2, $iElements, '/I must have 2 entries');
        self::assertInstanceOf(PdfNumber::class, $iElements[0]);
        self::assertInstanceOf(PdfNumber::class, $iElements[1]);
        /** @var PdfNumber $i0 */
        $i0 = $iElements[0];
        /** @var PdfNumber $i1 */
        $i1 = $iElements[1];
        self::assertSame(0, (int) $i0->value(), '/I[0] must be 0 for "fr"');
        self::assertSame(1, (int) $i1->value(), '/I[1] must be 1 for "en"');

        // The appearance Form XObject must show the DISPLAY texts, not the export keys
        $content = self::formXObjectContent($applied->objects);
        self::assertNotNull($content, 'A Form XObject must be present among applied objects');
        self::assertStringContainsString('(Francais) Tj', $content, 'Appearance must show display text "Francais"');
        self::assertStringContainsString('(English) Tj', $content, 'Appearance must show display text "English"');
        self::assertStringNotContainsString('(fr) Tj', $content, 'Appearance must not show export key "fr"');
        self::assertStringNotContainsString('(en) Tj', $content, 'Appearance must not show export key "en"');
    }

    // -------------------------------------------------------------------------
    // Test 8: testListboxNonStringElementThrows
    // Array with non-string element -> PdfException before TextString::of()
    // -------------------------------------------------------------------------

    public function testListboxNonStringElementThrows(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdfWithMultiListbox());
        $rf = self::resolveField($reader, 'tags');

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/listbox values must be strings/i');

        // Pass an array with integer elements; the signature accepts array<mixed>
        (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, [1, 2], self::makeAllocator());
    }
}
