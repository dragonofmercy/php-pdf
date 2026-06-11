<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Fill;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Form\Fill\FieldTree;
use DragonOfMercy\PhpPdf\Form\Fill\FieldValueApplier;
use DragonOfMercy\PhpPdf\Form\Fill\ResolvedField;
use DragonOfMercy\PhpPdf\Form\TextField;
use DragonOfMercy\PhpPdf\Reader\DictReader;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use PHPUnit\Framework\TestCase;

final class FieldValueApplierTextTest extends TestCase
{
    private static function buildPdfWithTextField(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new TextField(20, 20, 80, 8, name: 'city'));
        return $doc->output();
    }

    private static function resolveTextField(PdfReader $reader): ResolvedField
    {
        foreach ((new FieldTree($reader))->terminalFields() as $f) {
            if ($f->name === 'city') {
                return $f;
            }
        }
        self::fail('Field "city" not found in PDF');
    }

    private static function makeAllocator(): \Closure
    {
        $next = 9000;
        return static function () use (&$next): int { return $next++; };
    }

    // -----------------------------------------------------------------------
    // Test 1: filling a text field produces /V and /AP on the field object,
    //         and one Form XObject among the returned objects.
    // -----------------------------------------------------------------------

    public function testFillTextProducesValueAndAppearance(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdfWithTextField());
        $rf = self::resolveTextField($reader);

        $applied = (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, 'Paris', self::makeAllocator());

        $objects = $applied->objects;
        self::assertNotEmpty($objects, 'AppliedField must contain at least one object');

        // Find the object carrying /V and /AP (the field/widget object)
        $fieldObject = null;
        $apObject = null;
        foreach ($objects as $indirectObject) {
            $payload = $indirectObject->payload();
            if (!$payload instanceof Dictionary) {
                // Check for Form XObject (CompressedStream carrying a dict)
                // The subtype detection happens below
                continue;
            }
            $vRaw = $payload->get(Name::of('V'));
            $apRaw = $payload->get(Name::of('AP'));
            if ($vRaw !== null && $apRaw !== null) {
                $fieldObject = $payload;
            }
        }

        self::assertNotNull($fieldObject, 'No object found with both /V and /AP entries');

        $vValue = DictReader::decodeText($fieldObject->get(Name::of('V')));
        self::assertSame('Paris', $vValue, '/V must decode to the filled value "Paris"');

        // Find the Form XObject: an object whose stream dict has /Subtype /Form
        $hasFormXObject = false;
        foreach ($objects as $indirectObject) {
            $payload = $indirectObject->payload();
            // CompressedStream - check via streamDict()
            if (method_exists($payload, 'streamDict')) {
                /** @var \DragonOfMercy\PhpPdf\Writer\Object\CompressedStream $payload */
                $streamDict = $payload->streamDict();
                $subtypeRaw = $streamDict->get(Name::of('Subtype'));
                if ($subtypeRaw instanceof Name && $subtypeRaw->value() === 'Form') {
                    $hasFormXObject = true;
                    break;
                }
            }
        }
        self::assertTrue($hasFormXObject, 'One of the returned objects must be a Form XObject with /Subtype /Form');
    }

    // -----------------------------------------------------------------------
    // Test 2: the appearance stream's /Resources /Font contains the DR font ref
    // -----------------------------------------------------------------------

    public function testAppearanceReferencesDrFont(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdfWithTextField());
        $rf = self::resolveTextField($reader);

        $applied = (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, 'Paris', self::makeAllocator());

        // Find the Form XObject and verify /Resources /Font /Helv is present
        $found = false;
        foreach ($applied->objects as $indirectObject) {
            $payload = $indirectObject->payload();
            if (!method_exists($payload, 'streamDict')) {
                continue;
            }
            /** @var \DragonOfMercy\PhpPdf\Writer\Object\CompressedStream $payload */
            $streamDict = $payload->streamDict();
            $subtypeRaw = $streamDict->get(Name::of('Subtype'));
            if (!$subtypeRaw instanceof Name || $subtypeRaw->value() !== 'Form') {
                continue;
            }
            $resourcesRaw = $streamDict->get(Name::of('Resources'));
            if (!$resourcesRaw instanceof Dictionary) {
                continue;
            }
            $fontDict = $resourcesRaw->get(Name::of('Font'));
            if (!$fontDict instanceof Dictionary) {
                continue;
            }
            // The font alias used by the library is 'Helv' for Helvetica
            $helvEntry = $fontDict->get(Name::of('Helv'));
            if ($helvEntry !== null) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Form XObject /Resources /Font must contain a /Helv entry');
    }

    // -----------------------------------------------------------------------
    // Test 3: passing a non-string value to a text field throws PdfException
    // -----------------------------------------------------------------------

    public function testNonStringValueThrows(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdfWithTextField());
        $rf = self::resolveTextField($reader);

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/city/');

        (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, true, self::makeAllocator());
    }

    // -----------------------------------------------------------------------
    // Test 4: non-Standard-14 BaseFont in /DR /Font throws PdfException
    //
    // Builds a minimal valid PDF by hand (classic xref + trailer) containing an
    // AcroForm whose /DR /Font /Helv points to a font dict with /BaseFont
    // /ABCDEF+FreeSans (a subset-prefixed non-standard name). The single text
    // field has /DA (0 g /Helv 10 Tf). Asserts that apply() throws PdfException
    // whose message contains the field name and the offending base font name.
    // -----------------------------------------------------------------------

    public function testNonStandard14DrFontThrows(): void
    {
        // Build the PDF bytes by hand so we can control /BaseFont arbitrarily.
        //
        // Object layout:
        //   obj 1 : Catalog  -> AcroForm inline, /DR /Font /Helv = ref(10)
        //   obj 2 : Pages root
        //   obj 3 : Page     -> /Annots [ ref(11) ]
        //   obj 10: Font dict  /BaseFont /ABCDEF+FreeSans  (non-standard)
        //   obj 11: Field+widget (combined) /FT /Tx /Rect ... /DA (0 g /Helv 10 Tf)

        $body = "%PDF-1.4\n";

        // obj 1 : catalog with inline AcroForm
        $off1 = strlen($body);
        $body .= "1 0 obj\n";
        $body .= "<< /Type /Catalog /Pages 2 0 R\n";
        $body .= "   /AcroForm << /Fields [ 11 0 R ]\n";
        $body .= "               /DA (0 g /Helv 10 Tf)\n";
        $body .= "               /DR << /Font << /Helv 10 0 R >> >> >> >>\n";
        $body .= "endobj\n";

        // obj 2 : pages root
        $off2 = strlen($body);
        $body .= "2 0 obj\n<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>\nendobj\n";

        // obj 3 : page with the field widget as annotation
        $off3 = strlen($body);
        $body .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [ 0 0 595 842 ] /Annots [ 11 0 R ] >>\nendobj\n";

        // obj 10 : font dict with a non-standard BaseFont
        $off10 = strlen($body);
        $body .= "10 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /ABCDEF+FreeSans >>\nendobj\n";

        // obj 11 : combined field+widget (no separate widget object needed)
        $off11 = strlen($body);
        $body .= "11 0 obj\n";
        $body .= "<< /Type /Annot /Subtype /Widget\n";
        $body .= "   /FT /Tx /T (email)\n";
        $body .= "   /DA (0 g /Helv 10 Tf)\n";
        $body .= "   /Rect [ 50 700 300 720 ]\n";
        $body .= "   /P 3 0 R >>\n";
        $body .= "endobj\n";

        $xrefOffset = strlen($body);
        $body .= "xref\n";
        $body .= "0 4\n";
        $body .= "0000000000 65535 f \n";
        $body .= str_pad((string) $off1, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        $body .= str_pad((string) $off2, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        $body .= str_pad((string) $off3, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        $body .= "10 2\n";
        $body .= str_pad((string) $off10, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        $body .= str_pad((string) $off11, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        $body .= "trailer\n<< /Size 12 /Root 1 0 R >>\n";
        $body .= "startxref\n{$xrefOffset}\n%%EOF\n";

        $reader = PdfReader::fromBytes($body);
        $tree = new FieldTree($reader);
        $fields = $tree->terminalFields();

        $rf = null;
        foreach ($fields as $f) {
            if ($f->name === 'email') {
                $rf = $f;
            }
        }
        self::assertNotNull($rf, 'Field "email" must be discovered in the hand-built PDF');

        $next = 9000;
        $allocate = static function () use (&$next): int { return $next++; };

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/email/');
        $this->expectExceptionMessageMatches('/ABCDEF\+FreeSans/');

        (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, 'test@example.com', $allocate);
    }

    // -----------------------------------------------------------------------
    // Test 5: unsupported type throws PdfException
    // PushButton is not a fillable field type; applying a value must throw.
    // -----------------------------------------------------------------------

    public function testNonTextTypeThrows(): void
    {
        // Build a PDF with a pushbutton (unsupported fill type)
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new \DragonOfMercy\PhpPdf\Form\PushButton(20, 40, 30, 8, name: 'submit', caption: 'OK', action: \DragonOfMercy\PhpPdf\Form\ButtonAction::resetForm()));
        $reader = PdfReader::fromBytes($doc->output());

        $rf = null;
        foreach ((new FieldTree($reader))->terminalFields() as $f) {
            if ($f->name === 'submit') {
                $rf = $f;
            }
        }
        self::assertNotNull($rf, 'Field "submit" not found');

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/not yet supported/i');

        $next = 9000;
        $allocate = static function () use (&$next): int { return $next++; };
        (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, 'ignored', $allocate);
    }
}
