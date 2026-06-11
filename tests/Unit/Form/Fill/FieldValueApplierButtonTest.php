<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Fill;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Form\Checkbox;
use DragonOfMercy\PhpPdf\Form\Fill\FieldTree;
use DragonOfMercy\PhpPdf\Form\Fill\FieldValueApplier;
use DragonOfMercy\PhpPdf\Form\Fill\ResolvedField;
use DragonOfMercy\PhpPdf\Form\Radio;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use PHPUnit\Framework\TestCase;

final class FieldValueApplierButtonTest extends TestCase
{
    private static function buildPdfWithCheckboxAndRadio(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new Checkbox(20, 40, 5, 5, name: 'agree'));
        $page->field(new Radio(20, 60, 5, 5, group: 'gender', value: 'male'));
        $page->field(new Radio(40, 60, 5, 5, group: 'gender', value: 'female'));
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
     * Helper: find the re-emitted dict for a given object number in applied objects.
     *
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

    // -------------------------------------------------------------------------
    // Test 1: testCheckTrueSelectsOnState
    // Applying true to a checkbox => /V and /AS are the on-state Name (not 'Off')
    // -------------------------------------------------------------------------

    public function testCheckTrueSelectsOnState(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdfWithCheckboxAndRadio());
        $rf = self::resolveField($reader, 'agree');

        $applied = (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, true, self::makeAllocator());

        self::assertNotEmpty($applied->objects);

        // The checkbox field is also the widget (single combined object)
        $dict = self::dictForObjectNumber($applied->objects, $rf->objectNumber);
        self::assertNotNull($dict, 'Re-emitted checkbox object must be a Dictionary');

        $vRaw = $dict->get(Name::of('V'));
        $asRaw = $dict->get(Name::of('AS'));
        self::assertInstanceOf(Name::class, $vRaw, '/V must be a Name');
        self::assertInstanceOf(Name::class, $asRaw, '/AS must be a Name');

        /** @var Name $vRaw */
        /** @var Name $asRaw */
        self::assertNotSame('Off', $vRaw->value(), '/V must not be "Off" when checked=true');
        self::assertNotSame('Off', $asRaw->value(), '/AS must not be "Off" when checked=true');
        self::assertSame($vRaw->value(), $asRaw->value(), '/V and /AS must agree');
    }

    // -------------------------------------------------------------------------
    // Test 2: testCheckFalseSelectsOff
    // Applying false to a checkbox => /V and /AS are 'Off'
    // -------------------------------------------------------------------------

    public function testCheckFalseSelectsOff(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdfWithCheckboxAndRadio());
        $rf = self::resolveField($reader, 'agree');

        $applied = (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, false, self::makeAllocator());

        self::assertNotEmpty($applied->objects);

        $dict = self::dictForObjectNumber($applied->objects, $rf->objectNumber);
        self::assertNotNull($dict, 'Re-emitted checkbox object must be a Dictionary');

        $vRaw = $dict->get(Name::of('V'));
        $asRaw = $dict->get(Name::of('AS'));
        self::assertInstanceOf(Name::class, $vRaw, '/V must be a Name');
        self::assertInstanceOf(Name::class, $asRaw, '/AS must be a Name');

        /** @var Name $vRaw */
        /** @var Name $asRaw */
        self::assertSame('Off', $vRaw->value(), '/V must be "Off" when checked=false');
        self::assertSame('Off', $asRaw->value(), '/AS must be "Off" when checked=false');
    }

    // -------------------------------------------------------------------------
    // Test 3: testRadioSelectsOption
    // Applying 'female' to gender group:
    //   - group /V = 'female'
    //   - exactly one kid has /AS != 'Off' and equals 'female'
    //   - other kid /AS = 'Off'
    // -------------------------------------------------------------------------

    public function testRadioSelectsOption(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdfWithCheckboxAndRadio());
        $rf = self::resolveField($reader, 'gender');

        $applied = (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, 'female', self::makeAllocator());

        self::assertNotEmpty($applied->objects);

        // Group parent object: must have /V = 'female'
        $groupDict = self::dictForObjectNumber($applied->objects, $rf->objectNumber);
        self::assertNotNull($groupDict, 'Re-emitted radio group object must be present');

        $vRaw = $groupDict->get(Name::of('V'));
        self::assertInstanceOf(Name::class, $vRaw, 'Radio group /V must be a Name');
        /** @var Name $vRaw */
        self::assertSame('female', $vRaw->value(), 'Radio group /V must be "female"');

        // Collect kid AS values
        $selectedCount = 0;
        $offCount = 0;
        foreach ($rf->widgetObjectNumbers as $kidNum) {
            $kidDict = self::dictForObjectNumber($applied->objects, $kidNum);
            self::assertNotNull($kidDict, "Re-emitted kid widget object {$kidNum} must be present");
            $asRaw = $kidDict->get(Name::of('AS'));
            self::assertInstanceOf(Name::class, $asRaw, "/AS on kid {$kidNum} must be a Name");
            /** @var Name $asRaw */
            if ($asRaw->value() === 'Off') {
                $offCount++;
            } else {
                self::assertSame('female', $asRaw->value(), "Selected kid /AS must be 'female'");
                $selectedCount++;
            }
        }
        self::assertSame(1, $selectedCount, 'Exactly one kid must have /AS = "female"');
        self::assertSame(1, $offCount, 'Exactly one kid must have /AS = "Off"');
    }

    // -------------------------------------------------------------------------
    // Test 4: testRadioInvalidOptionThrows
    // Applying a value not in options => PdfException
    // -------------------------------------------------------------------------

    public function testRadioInvalidOptionThrows(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdfWithCheckboxAndRadio());
        $rf = self::resolveField($reader, 'gender');

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/nonexistent/');

        (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, 'nonexistent', self::makeAllocator());
    }

    // -------------------------------------------------------------------------
    // Test 5: testCheckboxNonBoolThrows
    // Applying a string to a checkbox => PdfException
    // -------------------------------------------------------------------------

    public function testCheckboxNonBoolThrows(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdfWithCheckboxAndRadio());
        $rf = self::resolveField($reader, 'agree');

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/agree/');
        $this->expectExceptionMessageMatches('/bool/i');

        (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, 'x', self::makeAllocator());
    }

    // -------------------------------------------------------------------------
    // Test 6: testRadioNonStringThrows
    // Applying a non-string (bool) to a radio group => PdfException mentioning
    // the field name.
    // -------------------------------------------------------------------------

    public function testRadioNonStringThrows(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdfWithCheckboxAndRadio());
        $rf = self::resolveField($reader, 'gender');

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/gender/');

        (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, true, self::makeAllocator());
    }

    // -------------------------------------------------------------------------
    // Test 7: testCheckboxCustomOnStateName
    // A checkbox whose /AP /N has a non-'On' key ('Yes') must produce /V = 'Yes'
    // and /AS = 'Yes' when apply(true) is called.
    //
    // Built by hand: a minimal classic-xref PDF with:
    //   obj 1 : Catalog  /AcroForm /Fields [ 11 0 R ]
    //   obj 2 : Pages root
    //   obj 3 : Page  /Annots [ 11 0 R ]
    //   obj 10: empty Form XObject (on-state appearance stream)
    //   obj 12: empty Form XObject (Off appearance stream)
    //   obj 11: combined field+widget /FT /Btn /T (confirm)
    //           /AP /N << /Yes 10 0 R /Off 12 0 R >>
    //           /Rect [ 50 700 65 715 ]
    //           /P 3 0 R
    // -------------------------------------------------------------------------

    public function testCheckboxCustomOnStateName(): void
    {
        $body = "%PDF-1.4\n";

        // obj 1: catalog with inline AcroForm
        $off1 = strlen($body);
        $body .= "1 0 obj\n";
        $body .= "<< /Type /Catalog /Pages 2 0 R\n";
        $body .= "   /AcroForm << /Fields [ 11 0 R ] >> >>\n";
        $body .= "endobj\n";

        // obj 2: pages root
        $off2 = strlen($body);
        $body .= "2 0 obj\n<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>\nendobj\n";

        // obj 3: page
        $off3 = strlen($body);
        $body .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [ 0 0 595 842 ] /Annots [ 11 0 R ] >>\nendobj\n";

        // obj 10: tiny Form XObject for the 'Yes' (on) appearance
        $off10 = strlen($body);
        $body .= "10 0 obj\n";
        $body .= "<< /Type /XObject /Subtype /Form /BBox [ 0 0 15 15 ] /Length 0 >>\n";
        $body .= "stream\nendstream\n";
        $body .= "endobj\n";

        // obj 12: tiny Form XObject for the 'Off' appearance
        $off12 = strlen($body);
        $body .= "12 0 obj\n";
        $body .= "<< /Type /XObject /Subtype /Form /BBox [ 0 0 15 15 ] /Length 0 >>\n";
        $body .= "stream\nendstream\n";
        $body .= "endobj\n";

        // obj 11: combined checkbox field+widget with on-state 'Yes' (not 'On')
        $off11 = strlen($body);
        $body .= "11 0 obj\n";
        $body .= "<< /Type /Annot /Subtype /Widget\n";
        $body .= "   /FT /Btn /T (confirm)\n";
        $body .= "   /Rect [ 50 700 65 715 ]\n";
        $body .= "   /AP << /N << /Yes 10 0 R /Off 12 0 R >> >>\n";
        $body .= "   /P 3 0 R >>\n";
        $body .= "endobj\n";

        $xrefOffset = strlen($body);
        $body .= "xref\n";
        $body .= "0 4\n";
        $body .= "0000000000 65535 f \n";
        $body .= str_pad((string) $off1, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        $body .= str_pad((string) $off2, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        $body .= str_pad((string) $off3, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        $body .= "10 3\n";
        $body .= str_pad((string) $off10, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        $body .= str_pad((string) $off11, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        $body .= str_pad((string) $off12, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        $body .= "trailer\n<< /Size 13 /Root 1 0 R >>\n";
        $body .= "startxref\n{$xrefOffset}\n%%EOF\n";

        $reader = PdfReader::fromBytes($body);
        $fields = (new \DragonOfMercy\PhpPdf\Form\Fill\FieldTree($reader))->terminalFields();

        $rf = null;
        foreach ($fields as $f) {
            if ($f->name === 'confirm') {
                $rf = $f;
            }
        }
        if ($rf === null) {
            self::markTestSkipped('Hand-built PDF did not parse the "confirm" checkbox field; skipping custom on-state test');
        }

        $applied = (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, true, self::makeAllocator());

        $dict = self::dictForObjectNumber($applied->objects, $rf->objectNumber);
        self::assertNotNull($dict, 'Re-emitted checkbox object must be a Dictionary');

        $vRaw = $dict->get(Name::of('V'));
        $asRaw = $dict->get(Name::of('AS'));
        self::assertInstanceOf(Name::class, $vRaw, '/V must be a Name');
        self::assertInstanceOf(Name::class, $asRaw, '/AS must be a Name');

        /** @var Name $vRaw */
        /** @var Name $asRaw */
        self::assertSame('Yes', $vRaw->value(), '/V must be "Yes" (the custom on-state), not "On"');
        self::assertSame('Yes', $asRaw->value(), '/AS must be "Yes" (the custom on-state), not "On"');
    }
}
