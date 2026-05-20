<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\FieldAppearance;
use DragonOfMercy\PhpPdf\Form\FormField;
use DragonOfMercy\PhpPdf\Form\TextField;
use PHPUnit\Framework\TestCase;

final class TextFieldTest extends TestCase
{
    public function testBasicConstruction(): void
    {
        $f = new TextField(x: 50.0, y: 100.0, width: 80.0, height: 8.0, name: 'firstname');
        self::assertSame(50.0, $f->x);
        self::assertSame(100.0, $f->y);
        self::assertSame(80.0, $f->width);
        self::assertSame(8.0, $f->height);
        self::assertSame('firstname', $f->name);
        self::assertSame('', $f->value);
        self::assertFalse($f->multiline);
        self::assertFalse($f->required);
        self::assertFalse($f->readOnly);
        self::assertNull($f->maxLength);
        self::assertNull($f->tooltip);
        self::assertNull($f->appearance);
    }

    public function testImplementsFormField(): void
    {
        $f = new TextField(50.0, 100.0, 80.0, 8.0, name: 'a');
        self::assertInstanceOf(FormField::class, $f);
        self::assertSame('a', $f->name());
    }

    public function testFullConstruction(): void
    {
        $appearance = new FieldAppearance();
        $f = new TextField(
            x: 10.0, y: 20.0, width: 100.0, height: 12.0,
            name: 'comment',
            value: 'hello',
            multiline: true,
            required: true,
            readOnly: false,
            maxLength: 200,
            tooltip: 'Your comment',
            appearance: $appearance,
        );
        self::assertSame('hello', $f->value);
        self::assertTrue($f->multiline);
        self::assertTrue($f->required);
        self::assertSame(200, $f->maxLength);
        self::assertSame('Your comment', $f->tooltip);
        self::assertSame($appearance, $f->appearance);
    }

    public function testWidthZeroThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Field width and height must be positive, got w=0 h=8');
        new TextField(0.0, 0.0, 0.0, 8.0, name: 'a');
    }

    public function testHeightNegativeThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Field width and height must be positive, got w=10 h=-1');
        new TextField(0.0, 0.0, 10.0, -1.0, name: 'a');
    }

    public function testEmptyNameThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Field name cannot be empty');
        new TextField(0.0, 0.0, 10.0, 8.0, name: '');
    }

    public function testMaxLengthZeroThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('TextField maxLength must be positive, got 0');
        new TextField(0.0, 0.0, 10.0, 8.0, name: 'a', maxLength: 0);
    }

    public function testMaxLengthNegativeThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('TextField maxLength must be positive, got -5');
        new TextField(0.0, 0.0, 10.0, 8.0, name: 'a', maxLength: -5);
    }
}
