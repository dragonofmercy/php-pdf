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
}
