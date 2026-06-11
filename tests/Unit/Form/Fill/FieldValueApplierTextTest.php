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
    // Test 4: unsupported type throws PdfException
    // Note: The from-scratch API only emits Standard-14 DR fonts (Helv),
    // so the non-standard-14 BaseFont throw cannot be triggered via Document
    // construction alone. This test verifies the non-Text-type guard instead.
    // -----------------------------------------------------------------------

    public function testNonTextTypeThrows(): void
    {
        // Build a PDF with a checkbox (non-Text type)
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new \DragonOfMercy\PhpPdf\Form\Checkbox(20, 40, 5, 5, name: 'agree'));
        $reader = PdfReader::fromBytes($doc->output());

        $rf = null;
        foreach ((new FieldTree($reader))->terminalFields() as $f) {
            if ($f->name === 'agree') {
                $rf = $f;
            }
        }
        self::assertNotNull($rf, 'Field "agree" not found');

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/not yet supported/i');

        $next = 9000;
        $allocate = static function () use (&$next): int { return $next++; };
        (new FieldValueApplier($reader, new MetricsRegistry()))
            ->apply($rf, 'ignored', $allocate);
    }
}
