<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\AcroFormEmitter;
use DragonOfMercy\PhpPdf\Form\Action\Calculate;
use DragonOfMercy\PhpPdf\Form\Action\FieldActions;
use DragonOfMercy\PhpPdf\Form\Action\Format;
use DragonOfMercy\PhpPdf\Form\ButtonAction;
use DragonOfMercy\PhpPdf\Form\Checkbox;
use DragonOfMercy\PhpPdf\Form\FieldAppearance;
use DragonOfMercy\PhpPdf\Form\FieldBorderStyle;
use DragonOfMercy\PhpPdf\Form\PushButton;
use DragonOfMercy\PhpPdf\Form\SignatureField;
use DragonOfMercy\PhpPdf\Form\SubmitFormat;
use DragonOfMercy\PhpPdf\Form\TextField;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class AcroFormEmitterTest extends TestCase
{
    public function testEmitTextFieldProducesWidgetWithFTTx(): void
    {
        $pageRef = PdfReference::to(1, 0);
        $field = new TextField(50.0, 100.0, 80.0, 8.0, name: 'firstname', value: 'Bob');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => $pageRef, 'pageHeightPt' => 841.89]];

        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        self::assertNotEmpty($emit['objects']);
        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/FT /Tx', $serialized);
        self::assertStringContainsString('/T (firstname)', $serialized);
        self::assertStringContainsString('/V (Bob)', $serialized);
        self::assertStringContainsString('/Subtype /Widget', $serialized);
    }

    public function testTextFieldMultilineSetsFlag13(): void
    {
        $field = new TextField(0.0, 0.0, 100.0, 50.0, name: 'a', multiline: true);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertMatchesRegularExpression('~/Ff \d+~', $serialized);
        if (preg_match('~/Ff (\d+)~', $serialized, $m) !== 1) {
            self::fail('/Ff entry must be present');
        }
        self::assertSame(4096, ((int) $m[1]) & 4096, '/Ff should have bit 13 (multiline) set');
    }

    public function testTextFieldReadOnlyAndRequiredFlagsCombined(): void
    {
        $field = new TextField(0.0, 0.0, 100.0, 8.0, name: 'a', required: true, readOnly: true);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        if (preg_match('~/Ff (\d+)~', $serialized, $m) !== 1) {
            self::fail('/Ff entry must be present');
        }
        $flags = (int) $m[1];
        self::assertSame(1, $flags & 1, 'bit 1 ReadOnly (mask 1)');
        self::assertSame(2, $flags & 2, 'bit 2 Required (mask 2)');
    }

    public function testTextFieldMaxLengthEmitted(): void
    {
        $field = new TextField(0.0, 0.0, 100.0, 8.0, name: 'a', maxLength: 50);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/MaxLen 50', $serialized);
    }

    public function testTextFieldTooltipEmitted(): void
    {
        $field = new TextField(0.0, 0.0, 100.0, 8.0, name: 'a', tooltip: 'My tooltip');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/TU (My tooltip)', $serialized);
    }

    public function testYFlipAppliedToRect(): void
    {
        $field = new TextField(50.0, 100.0, 80.0, 8.0, name: 'a');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 841.89]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        // user y=100, h=8, pageHeight=841.89 -> lly = 841.89 - 108 = 733.89, ury = 841.89 - 100 = 741.89
        self::assertStringContainsString('/Rect [50 733.89 130 741.89]', $serialized);
    }

    public function testDuplicateNamesThrows(): void
    {
        // PushButton is not a linkable type; two push buttons with the same name must still throw.
        $a = new PushButton(0.0, 0.0, 50.0, 8.0, name: 'dup', caption: 'A', action: ButtonAction::resetForm());
        $b = new PushButton(0.0, 20.0, 50.0, 8.0, name: 'dup', caption: 'B', action: ButtonAction::resetForm());
        $widgets = [
            ['field' => $a, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
            ['field' => $b, 'widgetRef' => PdfReference::to(11, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
        ];
        $nextId = 12;
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Duplicate field name 'dup'");
        (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
    }

    public function testLinkedTextFieldsWithSameNameAreAllowed(): void
    {
        $a = new TextField(0.0, 0.0, 80.0, 8.0, name: 'shared');
        $b = new TextField(0.0, 20.0, 80.0, 8.0, name: 'shared');
        $widgets = [
            ['field' => $a, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
            ['field' => $b, 'widgetRef' => PdfReference::to(11, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
        ];
        $nextId = 12;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
        self::assertNotEmpty($emit['objects']);
    }

    public function testDuplicatePushButtonNameThrows(): void
    {
        $a = new PushButton(0.0, 0.0, 60.0, 12.0, name: 'dup', caption: 'OK', action: ButtonAction::resetForm());
        $b = new PushButton(0.0, 20.0, 60.0, 12.0, name: 'dup', caption: 'Cancel', action: ButtonAction::resetForm());
        $widgets = [
            ['field' => $a, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
            ['field' => $b, 'widgetRef' => PdfReference::to(11, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
        ];
        $nextId = 12;
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Duplicate field name 'dup'");
        (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
    }

    public function testMixedTypesSameNameThrows(): void
    {
        $a = new TextField(0.0, 0.0, 80.0, 8.0, name: 'mix');
        $b = new Checkbox(0.0, 20.0, 5.0, 5.0, name: 'mix');
        $widgets = [
            ['field' => $a, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
            ['field' => $b, 'widgetRef' => PdfReference::to(11, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
        ];
        $nextId = 12;
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Duplicate field name 'mix'");
        (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
    }

    public function testEmitCheckboxUnchecked(): void
    {
        $field = new Checkbox(50.0, 100.0, 5.0, 5.0, name: 'agree');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 841.89]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/FT /Btn', $serialized);
        self::assertStringContainsString('/T (agree)', $serialized);
        self::assertStringContainsString('/AS /Off', $serialized);
        self::assertStringContainsString('/AP', $serialized);
        self::assertStringContainsString('/N', $serialized);
    }

    public function testEmitCheckboxChecked(): void
    {
        $field = new Checkbox(0.0, 0.0, 5.0, 5.0, name: 'a', checked: true);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/AS /On', $serialized);
        self::assertStringContainsString('/V /On', $serialized);
    }

    public function testEmitRadioGroupCreatesParentAndKids(): void
    {
        $r1 = new \DragonOfMercy\PhpPdf\Form\Radio(0.0, 0.0, 5.0, 5.0, group: 'civility', value: 'mr', checked: true);
        $r2 = new \DragonOfMercy\PhpPdf\Form\Radio(0.0, 10.0, 5.0, 5.0, group: 'civility', value: 'mrs');
        $r3 = new \DragonOfMercy\PhpPdf\Form\Radio(0.0, 20.0, 5.0, 5.0, group: 'civility', value: 'other');
        $widgets = [
            ['field' => $r1, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
            ['field' => $r2, 'widgetRef' => PdfReference::to(11, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
            ['field' => $r3, 'widgetRef' => PdfReference::to(12, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
        ];
        $nextId = 13;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        // Exactly ONE /T (civility) (on the parent only - kids do not have /T)
        self::assertSame(1, substr_count($serialized, '/T (civility)'));
        // /Kids array with 3 entries
        self::assertMatchesRegularExpression('~/Kids \[10 0 R 11 0 R 12 0 R\]~', $serialized);
        // /V on parent points to selected value
        self::assertStringContainsString('/V /mr', $serialized);
        // Each kid has /Parent reference to the parent field ref
        self::assertSame(3, substr_count($serialized, '/Parent '));
        // /AS on each kid: /mr (the checked one) or /Off (others)
        self::assertStringContainsString('/AS /mr', $serialized);
        self::assertSame(2, substr_count($serialized, '/AS /Off'));
        // Per PDF 32000-1:2008 Table 227:
        // - Radio (bit 16) = 1<<15 = 32768
        // - NoToggleToOff (bit 15) = 1<<14 = 16384
        // Combined = 49152. The old (buggy) value was 98304 = Radio + Pushbutton.
        if (preg_match_all('~/Ff (\d+)~', $serialized, $matches) > 0) {
            self::assertNotEmpty($matches[1], 'Expected at least one /Ff');
            $parentFlags = (int) $matches[1][0];
            self::assertSame(32768, $parentFlags & 32768, 'Radio bit (bit 16) set');
            self::assertSame(16384, $parentFlags & 16384, 'NoToggleToOff bit (bit 15) set');
            self::assertSame(0, $parentFlags & 65536, 'Pushbutton bit (bit 17) must NOT be set');
        } else {
            self::fail('preg_match_all failed');
        }
    }

    public function testEmitRadioMultipleGroups(): void
    {
        $a = new \DragonOfMercy\PhpPdf\Form\Radio(0.0, 0.0, 5.0, 5.0, group: 'g1', value: 'a', checked: true);
        $b = new \DragonOfMercy\PhpPdf\Form\Radio(0.0, 10.0, 5.0, 5.0, group: 'g2', value: 'x');
        $widgets = [
            ['field' => $a, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
            ['field' => $b, 'widgetRef' => PdfReference::to(11, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
        ];
        $nextId = 12;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/T (g1)', $serialized);
        self::assertStringContainsString('/T (g2)', $serialized);
    }

    public function testEmitComboboxListOfStrings(): void
    {
        $field = new \DragonOfMercy\PhpPdf\Form\Combobox(0.0, 0.0, 50.0, 8.0, name: 'c', options: ['A', 'B', 'C']);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/FT /Ch', $serialized);
        self::assertStringContainsString('/Opt [(A) (B) (C)]', $serialized);
        // bit 18 Combo (1 << 17 = 131072)
        if (preg_match('~/Ff (\d+)~', $serialized, $m) !== 1) {
            self::fail('/Ff entry must be present');
        }
        self::assertSame(131072, ((int) $m[1]) & 131072);
    }

    public function testEmitComboboxExportMap(): void
    {
        $field = new \DragonOfMercy\PhpPdf\Form\Combobox(0.0, 0.0, 50.0, 8.0, name: 'c', options: ['fr' => 'France', 'ch' => 'Suisse'], value: 'ch');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/Opt [[(fr) (France)] [(ch) (Suisse)]]', $serialized);
        self::assertStringContainsString('/V (ch)', $serialized);
    }

    public function testEmitComboboxEditableSetsFlag(): void
    {
        $field = new \DragonOfMercy\PhpPdf\Form\Combobox(0.0, 0.0, 50.0, 8.0, name: 'c', options: ['x'], editable: true);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        // bit 19 Edit (1 << 18 = 262144)
        if (preg_match('~/Ff (\d+)~', $serialized, $m) !== 1) {
            self::fail('/Ff entry must be present');
        }
        self::assertSame(262144, ((int) $m[1]) & 262144);
    }

    public function testEmitComboboxRejectsValueNotInOptions(): void
    {
        $field = new \DragonOfMercy\PhpPdf\Form\Combobox(0.0, 0.0, 50.0, 8.0, name: 'c', options: ['fr' => 'France'], value: 'zz');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Combobox value 'zz' not found in options for field 'c'");
        (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
    }

    public function testEmitListboxSingleValue(): void
    {
        $field = new \DragonOfMercy\PhpPdf\Form\Listbox(0.0, 0.0, 50.0, 30.0, name: 'l', options: ['a', 'b'], value: 'a');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/FT /Ch', $serialized);
        self::assertStringContainsString('/V (a)', $serialized);
        // Combo bit (1<<17 = 131072) must NOT be set ; MultiSelect bit (1<<21 = 2097152) must NOT be set
        if (preg_match('~/Ff (\d+)~', $serialized, $m) === 1) {
            self::assertSame(0, ((int) $m[1]) & 131072, 'Combo bit must not be set');
            self::assertSame(0, ((int) $m[1]) & 2097152, 'MultiSelect bit must not be set');
        }
        // If no /Ff at all (flags=0), that is fine - both bits are absent by default.
    }

    public function testEmitListboxMultiSelect(): void
    {
        $field = new \DragonOfMercy\PhpPdf\Form\Listbox(0.0, 0.0, 50.0, 30.0, name: 'l', options: ['a', 'b', 'c'], value: ['a', 'c'], multiSelect: true);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/V [(a) (c)]', $serialized);
        // MultiSelect bit (1 << 21 = 2097152) must be set
        if (preg_match('~/Ff (\d+)~', $serialized, $m) !== 1) {
            self::fail('/Ff entry must be present');
        }
        self::assertSame(2097152, ((int) $m[1]) & 2097152);
    }

    public function testEmitListboxRejectsValueNotInOptions(): void
    {
        $field = new \DragonOfMercy\PhpPdf\Form\Listbox(0.0, 0.0, 50.0, 30.0, name: 'l', options: ['a', 'b'], value: 'z');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Listbox value 'z' not found in options for field 'l'");
        (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
    }

    public function testEmitListboxRejectsMultipleValuesWhenSingleSelect(): void
    {
        $field = new \DragonOfMercy\PhpPdf\Form\Listbox(0.0, 0.0, 50.0, 30.0, name: 'l', options: ['a', 'b'], value: ['a', 'b'], multiSelect: false);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Listbox value must be a single string or null when multiSelect is false, got 2 entries for field 'l'");
        (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
    }

    public function testAcroFormDictContainsDAAndDRFontHelv(): void
    {
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'a');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/DA (0 g /Helv 10 Tf)', $serialized);
        self::assertStringContainsString('/DR', $serialized);
        self::assertStringContainsString('/Font', $serialized);
        self::assertStringContainsString('/Helv', $serialized);
        self::assertStringContainsString('/NeedAppearances true', $serialized);
    }

    public function testTwoEmissionsAreDeterministic(): void
    {
        $field = new TextField(50.0, 100.0, 80.0, 8.0, name: 'a', value: 'Bob');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];

        $n1 = 11;
        $emit1 = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $n1, 'a');
        $n2 = 11;
        $emit2 = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $n2, 'a');

        $s1 = '';
        foreach ($emit1['objects'] as $obj) {
            $s1 .= $obj->toBytes();
        }
        $s2 = '';
        foreach ($emit2['objects'] as $obj) {
            $s2 .= $obj->toBytes();
        }
        self::assertSame($s1, $s2, 'two emissions of the same input must produce identical bytes');
    }

    public function testWidgetWithBorderColorEmitsMKBC(): void
    {
        $appearance = new \DragonOfMercy\PhpPdf\Form\FieldAppearance(
            borderColor: \DragonOfMercy\PhpPdf\Color::rgb(255, 0, 0),
        );
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'a', appearance: $appearance);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/MK', $serialized);
        self::assertStringContainsString('/BC [1 0 0]', $serialized);
    }

    public function testWidgetWithBackgroundColorEmitsMKBG(): void
    {
        $appearance = new \DragonOfMercy\PhpPdf\Form\FieldAppearance(
            backgroundColor: \DragonOfMercy\PhpPdf\Color::rgb(240, 240, 240),
        );
        $field = new \DragonOfMercy\PhpPdf\Form\Combobox(0.0, 0.0, 50.0, 8.0, name: 'c', options: ['x'], appearance: $appearance);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/MK', $serialized);
        self::assertStringContainsString('/BG', $serialized);
    }

    public function testWidgetWithoutAppearanceDoesNotEmitMK(): void
    {
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'a');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringNotContainsString('/MK', $serialized);
    }

    public function testRadioKidEmitsMKWhenAppearanceSet(): void
    {
        $appearance = new \DragonOfMercy\PhpPdf\Form\FieldAppearance(
            borderColor: \DragonOfMercy\PhpPdf\Color::rgb(0, 128, 0),
        );
        $r = new \DragonOfMercy\PhpPdf\Form\Radio(0.0, 0.0, 5.0, 5.0, group: 'g', value: 'v', checked: true, appearance: $appearance);
        $widgets = [['field' => $r, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/MK', $serialized);
        self::assertStringContainsString('/BC', $serialized);
    }

    public function testWidgetWithTextColorEmitsCustomDA(): void
    {
        $appearance = new \DragonOfMercy\PhpPdf\Form\FieldAppearance(
            textColor: \DragonOfMercy\PhpPdf\Color::rgb(255, 0, 0),
        );
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'a', appearance: $appearance);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        // Per-widget /DA with red text color and the default Helvetica 10pt.
        self::assertStringContainsString('(1 0 0 rg /Helv 10 Tf)', $serialized);
        // Form-level /DA still present.
        self::assertStringContainsString('/DA (0 g /Helv 10 Tf)', $serialized);
    }

    public function testWidgetWithCourierFontEmitsCustomDA(): void
    {
        $appearance = new \DragonOfMercy\PhpPdf\Form\FieldAppearance(
            font: \DragonOfMercy\PhpPdf\Font::courier(),
            fontSize: 12.0,
        );
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'a', appearance: $appearance);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, [
            'Helv' => PdfReference::to(999, 0),
            'Cour' => PdfReference::to(998, 0),
        ], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('(0 g /Cour 12 Tf)', $serialized);
        self::assertStringContainsString('/Cour 998 0 R', $serialized);
    }

    public function testWidgetWithTimesFontEmitsTiRoAlias(): void
    {
        $appearance = new \DragonOfMercy\PhpPdf\Form\FieldAppearance(
            font: \DragonOfMercy\PhpPdf\Font::times(),
            fontSize: 14.0,
        );
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'a', appearance: $appearance);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, [
            'Helv' => PdfReference::to(999, 0),
            'TiRo' => PdfReference::to(997, 0),
        ], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('(0 g /TiRo 14 Tf)', $serialized);
        self::assertStringContainsString('/TiRo 997 0 R', $serialized);
    }

    public function testTextFieldCenterAlignEmitsQ1(): void
    {
        $appearance = new \DragonOfMercy\PhpPdf\Form\FieldAppearance(
            align: \DragonOfMercy\PhpPdf\TextAlign::CENTER,
        );
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'a', appearance: $appearance);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/Q 1', $serialized);
    }

    public function testTextFieldRightAlignEmitsQ2(): void
    {
        $appearance = new \DragonOfMercy\PhpPdf\Form\FieldAppearance(
            align: \DragonOfMercy\PhpPdf\TextAlign::RIGHT,
        );
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'a', appearance: $appearance);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/Q 2', $serialized);
    }

    public function testTextFieldLeftAlignDoesNotEmitQ(): void
    {
        // LEFT is the default; explicitly setting it should NOT emit /Q.
        $appearance = new \DragonOfMercy\PhpPdf\Form\FieldAppearance(
            align: \DragonOfMercy\PhpPdf\TextAlign::LEFT,
        );
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'a', appearance: $appearance);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringNotContainsString('/Q ', $serialized);
    }

    public function testWidgetWithoutAppearanceDoesNotEmitDA(): void
    {
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'a');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        // Only one /DA occurrence: the form-level default. No per-widget override.
        self::assertSame(1, substr_count($serialized, '/DA '));
    }

    public function testCustomFontInAppearanceThrows(): void
    {
        $appearance = new \DragonOfMercy\PhpPdf\Form\FieldAppearance(
            font: \DragonOfMercy\PhpPdf\Font::custom('SomeTtfAlias'),
        );
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'a', appearance: $appearance);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('FieldAppearance.font must be one of the Standard 14 fonts');
        (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
    }

    public function testEmitRequiresHelvEntryInStandardFontRefs(): void
    {
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'a');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('"Helv" entry');
        (new AcroFormEmitter(Unit::PT))->emit($widgets, [], $nextId, 'test');
    }

    public function testWidgetWithBorderWidthEmitsItInBorderArray(): void
    {
        $appearance = new \DragonOfMercy\PhpPdf\Form\FieldAppearance(
            borderColor: \DragonOfMercy\PhpPdf\Color::rgb(255, 0, 0),
            borderWidth: 2.0,
        );
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'a', appearance: $appearance);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) { $serialized .= $obj->toBytes(); }
        self::assertStringContainsString('/Border [0 0 2]', $serialized);
    }

    public function testWidgetWithFloatBorderWidthEmitsFloat(): void
    {
        $appearance = new \DragonOfMercy\PhpPdf\Form\FieldAppearance(borderWidth: 1.5);
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'a', appearance: $appearance);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) { $serialized .= $obj->toBytes(); }
        self::assertStringContainsString('/Border [0 0 1.5]', $serialized);
    }

    public function testWidgetWithoutBorderWidthEmitsZero(): void
    {
        // Critical for byte-identity: widget without appearance must produce /Border [0 0 0] with three integer zeros.
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'a');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) { $serialized .= $obj->toBytes(); }
        self::assertStringContainsString('/Border [0 0 0]', $serialized);
    }

    public function testRadioKidBorderWidthEmitted(): void
    {
        $appearance = new \DragonOfMercy\PhpPdf\Form\FieldAppearance(borderWidth: 3.0);
        $r = new \DragonOfMercy\PhpPdf\Form\Radio(0.0, 0.0, 5.0, 5.0, group: 'g', value: 'v', checked: true, appearance: $appearance);
        $widgets = [['field' => $r, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) { $serialized .= $obj->toBytes(); }
        self::assertStringContainsString('/Border [0 0 3]', $serialized);
    }

    public function testPasswordTextFieldEmitsFf8192(): void
    {
        // Password flag is bit 14 (1 << 13 = 8192), per PDF 32000-1:2008 Table 228.
        $field = new TextField(0.0, 0.0, 80.0, 8.0, name: 'pwd', password: true);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        if (preg_match('~/Ff (\d+)~', $serialized, $m) !== 1) {
            self::fail('/Ff entry must be present for password field');
        }
        self::assertSame(8192, ((int) $m[1]) & 8192, '/Ff must have Password bit (bit 14, mask 8192) set');
    }

    public function testPushButtonOpenUrlEmitsFlagsAndActionDict(): void
    {
        // Pushbutton flag is bit 17 (1 << 16 = 65536), per PDF 32000-1:2008 Table 227.
        $field = new PushButton(0.0, 0.0, 60.0, 12.0, name: 'go', caption: 'Click', action: ButtonAction::openUrl('https://example.com'));
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }

        // /Ff 65536 (Pushbutton bit)
        if (preg_match('~/Ff (\d+)~', $serialized, $m) !== 1) {
            self::fail('/Ff entry must be present for push button');
        }
        self::assertSame(65536, ((int) $m[1]) & 65536, '/Ff must have Pushbutton bit (bit 17, mask 65536) set');

        // /MK must be present and contain /CA for the caption
        self::assertStringContainsString('/MK', $serialized);
        self::assertStringContainsString('/CA (Click)', $serialized);

        // /A must contain /Type /Action, /S /URI, and /URI with the URL
        self::assertStringContainsString('/Type /Action', $serialized);
        self::assertStringContainsString('/S /URI', $serialized);
        self::assertStringContainsString('/URI (https://example.com)', $serialized);
    }

    public function testTextFieldWithActionsEmitsAAWithFAndCEntries(): void
    {
        $actions = FieldActions::new()
            ->format(Format::currency('EUR', 2))
            ->calculate(Calculate::sum(['a', 'b']));
        $field = new TextField(0.0, 0.0, 80.0, 8.0, name: 'total', actions: $actions);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }

        self::assertStringContainsString('/AA', $serialized);
        // /F sub-entry with JavaScript action
        self::assertStringContainsString('/F << /Type /Action /S /JavaScript /JS (AFNumber_Format\(2, 0, 0, 0, " EUR", false\);) >>', $serialized);
        // /C sub-entry with AFSimple_Calculate
        self::assertStringContainsString('/C << /Type /Action /S /JavaScript /JS (AFSimple_Calculate\("SUM", new Array\("a", "b"\)\);) >>', $serialized);
    }

    public function testPushButtonWithValueTriggerThrowsPdfException(): void
    {
        $actions = FieldActions::new()->calculate(Calculate::custom('c();'));
        $field = new PushButton(0.0, 0.0, 60.0, 12.0, name: 'btn', caption: 'OK', action: ButtonAction::openUrl('https://example.com'), actions: $actions);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('actions are not valid on a PushButton');
        (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
    }

    public function testPushButtonWithMouseEnterEmitsAAWithEEntry(): void
    {
        $actions = FieldActions::new()->onMouseEnter('app.beep(0);');
        $field = new PushButton(0.0, 0.0, 60.0, 12.0, name: 'btn', caption: 'OK', action: ButtonAction::openUrl('https://example.com'), actions: $actions);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }

        self::assertStringContainsString('/AA', $serialized);
        self::assertStringContainsString('/E << /Type /Action /S /JavaScript /JS (app.beep\(0\);) >>', $serialized);
    }

    public function testFieldWithNoActionsDoesNotEmitAA(): void
    {
        $field = new TextField(0.0, 0.0, 80.0, 8.0, name: 'plain');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }

        self::assertStringNotContainsString('/AA', $serialized);
    }

    public function testRadioKidWithValueTriggerThrowsPdfException(): void
    {
        $actions = FieldActions::new()->calculate(Calculate::custom('c();'));
        $r = new \DragonOfMercy\PhpPdf\Form\Radio(0.0, 0.0, 5.0, 5.0, group: 'civility', value: 'mr', actions: $actions);
        $widgets = [['field' => $r, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('actions are not valid on a Radio');
        (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
    }

    public function testCalculationOrderListsCalculateFieldsInDeclarationOrder(): void
    {
        $total = new TextField(0.0, 30.0, 30.0, 8.0, name: 'total',
            actions: FieldActions::new()->calculate(Calculate::sum(['a', 'b'])));
        $grand = new TextField(0.0, 40.0, 30.0, 8.0, name: 'grand',
            actions: FieldActions::new()->calculate(Calculate::sum(['total'])));
        $plain = new TextField(0.0, 10.0, 30.0, 8.0, name: 'a');
        // Declaration order: plain (no calc), total (calc), grand (calc).
        $widgets = [
            ['field' => $plain, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
            ['field' => $total, 'widgetRef' => PdfReference::to(11, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
            ['field' => $grand, 'widgetRef' => PdfReference::to(12, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
        ];
        $nextId = 13;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }

        // /CO lists 11 0 R (total) before 12 0 R (grand), and excludes 10 0 R (plain).
        self::assertStringContainsString('/CO [11 0 R 12 0 R]', $serialized);
    }

    public function testNoCalculationOrderWhenNoCalculateField(): void
    {
        $field = new TextField(0.0, 0.0, 30.0, 8.0, name: 'a');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }

        self::assertStringNotContainsString('/CO', $serialized);
    }

    public function testSubmitFormActionEmitsUrlFileSpecAndFlags(): void
    {
        $field = PushButton::of(0.0, 0.0, 60.0, 12.0, name: 'send', caption: 'Send',
            action: ButtonAction::submit('https://example.com/post', SubmitFormat::HTML));
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }

        self::assertStringContainsString('/S /SubmitForm', $serialized);
        self::assertStringContainsString('/F << /FS /URL /F (https://example.com/post) >>', $serialized);
        self::assertStringContainsString('/Flags 4', $serialized);
    }

    public function testSubmitFormFdfEmitsFlagsZero(): void
    {
        $field = PushButton::of(0.0, 0.0, 60.0, 12.0, name: 'send', caption: 'Send',
            action: ButtonAction::submit('https://example.com/post'));
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }

        self::assertStringContainsString('/Flags 0', $serialized);
    }

    public function testVisibleSignatureFieldEmitsSigWidgetWithoutValue(): void
    {
        $field = SignatureField::visible(10.0, 20.0, 60.0, 30.0, name: 'sig1');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }

        self::assertStringContainsString('/FT /Sig', $serialized);
        self::assertStringContainsString('/T (sig1)', $serialized);
        self::assertStringContainsString('/SigFlags 3', $serialized);
        self::assertStringNotContainsString('/V ', $serialized);
    }

    public function testInvisibleSignatureFieldEmitsZeroRect(): void
    {
        $field = SignatureField::invisible(name: 'sig2');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }

        self::assertStringContainsString('/Rect [0 0 0 0]', $serialized);
    }

    public function testSignatureFieldReadOnlyRequiredFlags(): void
    {
        $field = SignatureField::visible(0.0, 0.0, 50.0, 20.0, name: 'sig', required: true, readOnly: true);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        if (preg_match('~/Ff (\d+)~', $serialized, $m) !== 1) {
            self::fail('/Ff entry must be present');
        }
        $flags = (int) $m[1];
        self::assertSame(1, $flags & 1, 'ReadOnly bit');
        self::assertSame(2, $flags & 2, 'Required bit');
    }

    public function testNoSigFlagsWhenNoSignatureField(): void
    {
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'a');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringNotContainsString('/SigFlags', $serialized);
    }

    public function testComboboxWithValidateEmitsAAWithVEntry(): void
    {
        $actions = FieldActions::new()->validate(\DragonOfMercy\PhpPdf\Form\Action\Validate::range(0, 100));
        $field = new \DragonOfMercy\PhpPdf\Form\Combobox(0.0, 0.0, 80.0, 8.0, name: 'c', options: ['fr' => 'France'], value: 'fr', actions: $actions);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }

        self::assertStringContainsString('/AA', $serialized);
        self::assertStringContainsString('/V << /Type /Action /S /JavaScript /JS (AFRange_Validate\(true, 0, true, 100\);) >>', $serialized);
    }

    public function testHiddenAppearanceEmitsAnnotationFlagF(): void
    {
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'h', appearance: new FieldAppearance(hidden: true));
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
        $serialized = '';
        foreach ($emit['objects'] as $obj) { $serialized .= $obj->toBytes(); }
        self::assertStringContainsString('/F 2', $serialized);
    }

    public function testNoHiddenMeansNoAnnotationFlag(): void
    {
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'h');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
        $serialized = '';
        foreach ($emit['objects'] as $obj) { $serialized .= $obj->toBytes(); }
        self::assertStringNotContainsString('/F 2', $serialized);
    }

    public function testNoExportSetsFfBitFour(): void
    {
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'n', appearance: new FieldAppearance(noExport: true));
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
        $serialized = '';
        foreach ($emit['objects'] as $obj) { $serialized .= $obj->toBytes(); }
        if (preg_match('~/Ff (\d+)~', $serialized, $m) !== 1) {
            self::fail('/Ff entry must be present when noExport is set');
        }
        self::assertSame(4, ((int) $m[1]) & 4, 'NoExport bit (mask 4) must be set');
    }

    public function testBorderStyleBeveledEmitsBS(): void
    {
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'b',
            appearance: new FieldAppearance(borderWidth: 2.0, borderStyle: FieldBorderStyle::BEVELED));
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
        $serialized = '';
        foreach ($emit['objects'] as $obj) { $serialized .= $obj->toBytes(); }
        self::assertStringContainsString('/BS << /W 2 /S /B >>', $serialized);
    }

    public function testBorderStyleDashedEmitsDashArray(): void
    {
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 'd',
            appearance: new FieldAppearance(borderWidth: 1.0, borderStyle: FieldBorderStyle::DASHED));
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
        $serialized = '';
        foreach ($emit['objects'] as $obj) { $serialized .= $obj->toBytes(); }
        self::assertStringContainsString('/BS << /W 1 /S /D /D [3] >>', $serialized);
    }

    public function testTextFieldDefaultValueDecouplesDV(): void
    {
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 't', value: 'current', defaultValue: 'original');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
        $serialized = '';
        foreach ($emit['objects'] as $obj) { $serialized .= $obj->toBytes(); }
        self::assertStringContainsString('/V (current)', $serialized);
        self::assertStringContainsString('/DV (original)', $serialized);
    }

    public function testTextFieldNullDefaultValueMirrorsValue(): void
    {
        $field = new TextField(0.0, 0.0, 50.0, 8.0, name: 't', value: 'x');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
        $serialized = '';
        foreach ($emit['objects'] as $obj) { $serialized .= $obj->toBytes(); }
        self::assertStringContainsString('/V (x)', $serialized);
        self::assertStringContainsString('/DV (x)', $serialized);
    }

    public function testCheckboxDefaultValueDecouplesDV(): void
    {
        $field = new Checkbox(0.0, 0.0, 5.0, 5.0, name: 'c', checked: true, defaultValue: false);
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
        $serialized = '';
        foreach ($emit['objects'] as $obj) { $serialized .= $obj->toBytes(); }
        self::assertStringContainsString('/V /On', $serialized);
        self::assertStringContainsString('/DV /Off', $serialized);
    }

    public function testComboboxDefaultValueNotInOptionsThrows(): void
    {
        $field = new \DragonOfMercy\PhpPdf\Form\Combobox(0.0, 0.0, 50.0, 8.0, name: 'cb', options: ['a' => 'A'], value: 'a', defaultValue: 'zzz');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $this->expectExceptionMessage("Combobox default value 'zzz' not found in options for field 'cb'");
        (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
    }

    public function testUncheckedCheckboxWithNullDefaultEmitsNoDV(): void
    {
        // Byte-identity gotcha: an unchecked checkbox with no defaultValue must
        // emit neither /V nor /DV, exactly as before the decoupling.
        $field = new Checkbox(0.0, 0.0, 5.0, 5.0, name: 'c');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
        $serialized = '';
        foreach ($emit['objects'] as $obj) { $serialized .= $obj->toBytes(); }
        self::assertStringNotContainsString('/DV', $serialized);
        self::assertStringNotContainsString('/V /On', $serialized);
    }

    public function testComboboxNullDefaultMirrorsValueDV(): void
    {
        $field = new \DragonOfMercy\PhpPdf\Form\Combobox(0.0, 0.0, 50.0, 8.0, name: 'cb', options: ['fr' => 'France', 'ch' => 'Suisse'], value: 'ch');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
        $serialized = '';
        foreach ($emit['objects'] as $obj) { $serialized .= $obj->toBytes(); }
        self::assertStringContainsString('/V (ch)', $serialized);
        self::assertStringContainsString('/DV (ch)', $serialized);
    }

    public function testListboxDefaultValueNotInOptionsThrows(): void
    {
        $field = new \DragonOfMercy\PhpPdf\Form\Listbox(0.0, 0.0, 50.0, 24.0, name: 'lb', options: ['a', 'b'], value: 'a', defaultValue: 'zzz');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0]];
        $nextId = 11;
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Listbox default value 'zzz' not found in options for field 'lb'");
        (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
    }

    public function testSingletonFieldsStillEmitOneObjectEach(): void
    {
        // Regression pin: two TextFields with DIFFERENT names 'a' and 'b' must each
        // produce exactly one widget IndirectObject. The AcroForm dict is the third
        // object. Total = 3. This pins the singleton-dispatch path so the grouping
        // refactor cannot silently change allocation counts.
        $a = new TextField(0.0, 0.0, 80.0, 8.0, name: 'a');
        $b = new TextField(0.0, 20.0, 80.0, 8.0, name: 'b');
        $widgets = [
            ['field' => $a, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
            ['field' => $b, 'widgetRef' => PdfReference::to(11, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
        ];
        $nextId = 12;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
        // 2 widget objects + 1 AcroForm dict = 3 total
        self::assertCount(3, $emit['objects']);
    }

    public function testLinkedTextEmitsParentWithKidsAndNoKidT(): void
    {
        // Two TextFields sharing name 'shared'; first has value 'hi'.
        // Expects: /T (shared) exactly once (on the parent), /Kids present,
        // /V (hi) on the parent, /Parent on each kid.
        $a = new TextField(0.0, 0.0, 80.0, 8.0, name: 'shared', value: 'hi');
        $b = new TextField(0.0, 20.0, 80.0, 8.0, name: 'shared');
        $widgets = [
            ['field' => $a, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
            ['field' => $b, 'widgetRef' => PdfReference::to(11, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
        ];
        $nextId = 12;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }

        // /T appears exactly once (parent only; kids have no /T)
        self::assertSame(1, substr_count($serialized, '/T (shared)'), '/T (shared) must appear exactly once (parent only)');
        // Parent carries /Kids
        self::assertStringContainsString('/Kids', $serialized);
        // Parent carries /V (hi) from the first widget
        self::assertStringContainsString('/V (hi)', $serialized);
        // Each kid has a /Parent back-reference
        self::assertGreaterThanOrEqual(2, substr_count($serialized, '/Parent '), '/Parent must appear on both kids');
    }

    public function testLinkedCheckboxKidsEachHaveOwnAP(): void
    {
        // Two Checkboxes sharing name 'agree'; first is checked.
        // Expects: /AS appears twice (once per kid), /V /On on the parent.
        $a = new Checkbox(0.0, 0.0, 5.0, 5.0, name: 'agree', checked: true);
        $b = new Checkbox(0.0, 20.0, 5.0, 5.0, name: 'agree');
        $widgets = [
            ['field' => $a, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
            ['field' => $b, 'widgetRef' => PdfReference::to(11, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
        ];
        $nextId = 12;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }

        // Each kid carries its own /AS entry and its own /AP appearance dict
        self::assertSame(2, substr_count($serialized, '/AS '), 'each kid must have its own /AS');
        self::assertSame(2, substr_count($serialized, '/AP '), 'each kid must have its own /AP');
        // Parent carries /V /On because first widget is checked
        self::assertStringContainsString('/V /On', $serialized);
    }

    public function testLinkedComboboxOptOnParent(): void
    {
        // Two Comboboxes sharing name 'country', options ['FR','CH'], first value 'FR'.
        // Expects: /Opt appears exactly once (parent), /V (FR) on the parent.
        $a = new \DragonOfMercy\PhpPdf\Form\Combobox(0.0, 0.0, 60.0, 8.0, name: 'country', options: ['FR', 'CH'], value: 'FR');
        $b = new \DragonOfMercy\PhpPdf\Form\Combobox(0.0, 20.0, 60.0, 8.0, name: 'country', options: ['FR', 'CH']);
        $widgets = [
            ['field' => $a, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
            ['field' => $b, 'widgetRef' => PdfReference::to(11, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
        ];
        $nextId = 12;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }

        // /Opt must appear exactly once (on the parent, not on any kid)
        self::assertSame(1, substr_count($serialized, '/Opt'), '/Opt must appear exactly once');
        // Parent carries /V (FR) from the first widget
        self::assertStringContainsString('/V (FR)', $serialized);
    }

    public function testActionsOnNonFirstLinkedWidgetThrows(): void
    {
        // First widget has no actions; second widget has a Format action.
        // Expects PdfException with message containing "Linked field 'shared'".
        $a = new TextField(0.0, 0.0, 80.0, 8.0, name: 'shared');
        $b = new TextField(0.0, 20.0, 80.0, 8.0, name: 'shared', actions: FieldActions::new()->onFocus('app.beep(0);'));
        $widgets = [
            ['field' => $a, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
            ['field' => $b, 'widgetRef' => PdfReference::to(11, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
        ];
        $nextId = 12;
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Linked field 'shared'");
        (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
    }

    public function testLinkedCalculateFieldPutsParentRefInCalculationOrder(): void
    {
        // Linked TextField group whose first widget has a calculate action.
        // /CO must reference the PARENT object (allocated at nextId), not a kid.
        $a = new TextField(0.0, 0.0, 80.0, 8.0, name: 'shared',
            actions: FieldActions::new()->calculate(Calculate::sum(['x', 'y'])));
        $b = new TextField(0.0, 20.0, 80.0, 8.0, name: 'shared');
        $widgets = [
            ['field' => $a, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
            ['field' => $b, 'widgetRef' => PdfReference::to(11, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
        ];
        $nextId = 12; // parent will be object 12.
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }

        // /CO references the parent (12 0 R), and excludes the kid refs 10/11.
        self::assertStringContainsString('/CO [12 0 R]', $serialized);
    }

    public function testDuplicateSignatureFieldNameThrows(): void
    {
        $a = SignatureField::invisible(name: 'sig');
        $b = SignatureField::invisible(name: 'sig');
        $widgets = [
            ['field' => $a, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
            ['field' => $b, 'widgetRef' => PdfReference::to(11, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
        ];
        $nextId = 12;
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Duplicate field name 'sig'");
        (new AcroFormEmitter(Unit::PT))->emit($widgets, ['Helv' => PdfReference::to(999, 0)], $nextId, 'test');
    }
}
