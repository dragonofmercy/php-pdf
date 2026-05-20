<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\FormField;
use DragonOfMercy\PhpPdf\Form\Radio;
use PHPUnit\Framework\TestCase;

final class RadioTest extends TestCase
{
    public function testBasicConstruction(): void
    {
        $r = new Radio(x: 50.0, y: 100.0, width: 5.0, height: 5.0, group: 'civility', value: 'mr');
        self::assertSame('civility', $r->group);
        self::assertSame('mr', $r->value);
        self::assertFalse($r->checked);
        self::assertInstanceOf(FormField::class, $r);
        self::assertSame('civility', $r->name(), 'name() should mirror group');
    }

    public function testCheckedConstruction(): void
    {
        $r = new Radio(0.0, 0.0, 5.0, 5.0, group: 'g', value: 'v', checked: true, required: true, tooltip: 't');
        self::assertTrue($r->checked);
        self::assertTrue($r->required);
        self::assertSame('t', $r->tooltip);
    }

    public function testWidthZeroThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Field width and height must be positive, got w=0 h=5');
        new Radio(0.0, 0.0, 0.0, 5.0, group: 'g', value: 'v');
    }

    public function testEmptyGroupThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Radio group and value cannot be empty');
        new Radio(0.0, 0.0, 5.0, 5.0, group: '', value: 'v');
    }

    public function testEmptyValueThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Radio group and value cannot be empty');
        new Radio(0.0, 0.0, 5.0, 5.0, group: 'g', value: '');
    }
}
