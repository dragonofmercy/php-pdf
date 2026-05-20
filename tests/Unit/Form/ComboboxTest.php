<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\Combobox;
use DragonOfMercy\PhpPdf\Form\FormField;
use PHPUnit\Framework\TestCase;

final class ComboboxTest extends TestCase
{
    public function testListOfStringsConstruction(): void
    {
        $c = new Combobox(
            x: 0.0, y: 0.0, width: 50.0, height: 8.0,
            name: 'country',
            options: ['France', 'Suisse', 'Belgique'],
        );
        self::assertSame(['France', 'Suisse', 'Belgique'], $c->options);
        self::assertNull($c->value);
        self::assertFalse($c->editable);
        self::assertInstanceOf(FormField::class, $c);
        self::assertSame('country', $c->name());
    }

    public function testExportMapConstruction(): void
    {
        $c = new Combobox(
            0.0, 0.0, 50.0, 8.0,
            name: 'cc',
            options: ['fr' => 'France', 'ch' => 'Suisse'],
            value: 'ch',
        );
        self::assertSame(['fr' => 'France', 'ch' => 'Suisse'], $c->options);
        self::assertSame('ch', $c->value);
    }

    public function testEditableFlag(): void
    {
        $c = new Combobox(0.0, 0.0, 50.0, 8.0, name: 'a', options: ['x'], editable: true);
        self::assertTrue($c->editable);
    }

    public function testEmptyOptionsThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Combobox options list cannot be empty for field 'a'");
        new Combobox(0.0, 0.0, 50.0, 8.0, name: 'a', options: []);
    }

    public function testWidthZeroThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Field width and height must be positive, got w=0 h=8');
        new Combobox(0.0, 0.0, 0.0, 8.0, name: 'a', options: ['x']);
    }

    public function testEmptyNameThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Field name cannot be empty');
        new Combobox(0.0, 0.0, 50.0, 8.0, name: '', options: ['x']);
    }
}
