<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\Checkbox;
use DragonOfMercy\PhpPdf\Form\FormField;
use PHPUnit\Framework\TestCase;

final class CheckboxTest extends TestCase
{
    public function testBasicConstruction(): void
    {
        $f = new Checkbox(x: 50.0, y: 100.0, width: 5.0, height: 5.0, name: 'agree');
        self::assertSame('agree', $f->name);
        self::assertFalse($f->checked);
        self::assertFalse($f->required);
        self::assertFalse($f->readOnly);
        self::assertNull($f->tooltip);
        self::assertNull($f->appearance);
        self::assertInstanceOf(FormField::class, $f);
        self::assertSame('agree', $f->name());
    }

    public function testCheckedConstruction(): void
    {
        $f = new Checkbox(0.0, 0.0, 5.0, 5.0, name: 'a', checked: true, required: true, tooltip: 'tip');
        self::assertTrue($f->checked);
        self::assertTrue($f->required);
        self::assertSame('tip', $f->tooltip);
    }

    public function testWidthZeroThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Field width and height must be positive, got w=0 h=5');
        new Checkbox(0.0, 0.0, 0.0, 5.0, name: 'a');
    }

    public function testEmptyNameThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Field name cannot be empty');
        new Checkbox(0.0, 0.0, 5.0, 5.0, name: '');
    }
}
