<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\FieldAppearance;
use DragonOfMercy\PhpPdf\Form\SignatureField;
use PHPUnit\Framework\TestCase;

final class SignatureFieldTest extends TestCase
{
    public function testVisibleStoresDimensionsAndFlags(): void
    {
        $appearance = new FieldAppearance(borderWidth: 1.0);
        $f = SignatureField::visible(10.0, 20.0, 60.0, 30.0, name: 'sig1', appearance: $appearance);
        self::assertSame('sig1', $f->name());
        self::assertTrue($f->visible);
        self::assertSame(['x' => 10.0, 'y' => 20.0, 'width' => 60.0, 'height' => 30.0], $f->dimensions());
        self::assertSame($appearance, $f->appearance());
        self::assertNull($f->actions());
    }

    public function testInvisibleHasZeroDimensionsAndNoAppearance(): void
    {
        $f = SignatureField::invisible(name: 'sig2');
        self::assertSame('sig2', $f->name());
        self::assertFalse($f->visible);
        self::assertFalse($f->required);
        self::assertFalse($f->readOnly);
        self::assertSame(['x' => 0.0, 'y' => 0.0, 'width' => 0.0, 'height' => 0.0], $f->dimensions());
        self::assertNull($f->appearance());
        self::assertNull($f->actions());
    }

    public function testVisibleEmptyNameThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Field name cannot be empty');
        SignatureField::visible(0.0, 0.0, 10.0, 10.0, name: '');
    }

    public function testInvisibleEmptyNameThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Field name cannot be empty');
        SignatureField::invisible(name: '');
    }

    public function testVisibleNonPositiveWidthThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Field width and height must be positive, got w=0 h=10');
        SignatureField::visible(0.0, 0.0, 0.0, 10.0, name: 'sig');
    }

    public function testVisibleNonPositiveHeightThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Field width and height must be positive, got w=10 h=-1');
        SignatureField::visible(0.0, 0.0, 10.0, -1.0, name: 'sig');
    }

    public function testRequiredAndReadOnlyStored(): void
    {
        $f = SignatureField::visible(0.0, 0.0, 10.0, 10.0, name: 'sig', required: true, readOnly: true);
        self::assertTrue($f->required);
        self::assertTrue($f->readOnly);
    }
}
