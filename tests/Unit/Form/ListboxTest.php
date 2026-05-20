<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\FormField;
use DragonOfMercy\PhpPdf\Form\Listbox;
use PHPUnit\Framework\TestCase;

final class ListboxTest extends TestCase
{
    public function testListConstruction(): void
    {
        $l = new Listbox(0.0, 0.0, 50.0, 30.0, name: 'i', options: ['a', 'b', 'c']);
        self::assertNull($l->value);
        self::assertFalse($l->multiSelect);
        self::assertInstanceOf(FormField::class, $l);
        self::assertSame('i', $l->name());
    }

    public function testSingleValueString(): void
    {
        $l = new Listbox(0.0, 0.0, 50.0, 30.0, name: 'i', options: ['a', 'b'], value: 'a');
        self::assertSame('a', $l->value);
    }

    public function testMultiSelectListValue(): void
    {
        $l = new Listbox(0.0, 0.0, 50.0, 30.0, name: 'i', options: ['a', 'b'], value: ['a', 'b'], multiSelect: true);
        self::assertSame(['a', 'b'], $l->value);
        self::assertTrue($l->multiSelect);
    }

    public function testExportMapOptions(): void
    {
        $l = new Listbox(0.0, 0.0, 50.0, 30.0, name: 'i', options: ['x' => 'X', 'y' => 'Y']);
        self::assertSame(['x' => 'X', 'y' => 'Y'], $l->options);
    }

    public function testEmptyOptionsThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Listbox options list cannot be empty for field 'i'");
        new Listbox(0.0, 0.0, 50.0, 30.0, name: 'i', options: []);
    }

    public function testWidthZeroThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Field width and height must be positive, got w=0 h=30');
        new Listbox(0.0, 0.0, 0.0, 30.0, name: 'i', options: ['x']);
    }

    public function testEmptyNameThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Field name cannot be empty');
        new Listbox(0.0, 0.0, 50.0, 30.0, name: '', options: ['x']);
    }
}
