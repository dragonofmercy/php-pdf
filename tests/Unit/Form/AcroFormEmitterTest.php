<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\AcroFormEmitter;
use DragonOfMercy\PhpPdf\Form\Checkbox;
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
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, $nextId, 'test');

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
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, $nextId, 'test');

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
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, $nextId, 'test');

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
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, $nextId, 'test');

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
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, $nextId, 'test');

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
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        // user y=100, h=8, pageHeight=841.89 -> lly = 841.89 - 108 = 733.89, ury = 841.89 - 100 = 741.89
        self::assertStringContainsString('/Rect [50 733.89 130 741.89]', $serialized);
    }

    public function testDuplicateNamesThrows(): void
    {
        $a = new TextField(0.0, 0.0, 50.0, 8.0, name: 'dup');
        $b = new TextField(0.0, 20.0, 50.0, 8.0, name: 'dup');
        $widgets = [
            ['field' => $a, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
            ['field' => $b, 'widgetRef' => PdfReference::to(11, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 800.0],
        ];
        $nextId = 12;
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Duplicate field name 'dup'");
        (new AcroFormEmitter(Unit::PT))->emit($widgets, $nextId, 'test');
    }

    public function testEmitCheckboxUnchecked(): void
    {
        $field = new Checkbox(50.0, 100.0, 5.0, 5.0, name: 'agree');
        $widgets = [['field' => $field, 'widgetRef' => PdfReference::to(10, 0), 'pageRef' => PdfReference::to(1, 0), 'pageHeightPt' => 841.89]];
        $nextId = 11;
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, $nextId, 'test');

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
        $emit = (new AcroFormEmitter(Unit::PT))->emit($widgets, $nextId, 'test');

        $serialized = '';
        foreach ($emit['objects'] as $obj) {
            $serialized .= $obj->toBytes();
        }
        self::assertStringContainsString('/AS /On', $serialized);
        self::assertStringContainsString('/V /On', $serialized);
    }
}
