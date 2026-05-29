<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\{
    Barcode,
    Code128,
    Code39,
    Code93,
    Ean13,
    Ean8,
    Itf,
    QrCode,
    SizedBarcode,
    Upca,
};
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\{Document, NextPosition, Unit};
use PHPUnit\Framework\TestCase;

/**
 * Covers SizedBarcode on the 7 1D barcodes: withModuleSize() stores + propagates,
 * intrinsicWidth() mirrors widthForModule(), and Page::barcode() can omit w
 * when the barcode is sized.
 */
final class WithModuleSizeTest extends TestCase
{
    /**
     * @return iterable<string, array{0: Barcode&SizedBarcode}>
     */
    public static function sizedProvider(): iterable
    {
        yield 'Code39'  => [Code39::of('HELLO')];
        yield 'Code93'  => [Code93::of('HELLO')];
        yield 'Code128' => [Code128::of('HELLO')];
        yield 'Ean8'    => [Ean8::of('1234567')];
        yield 'Ean13'   => [Ean13::of('123456789012')];
        yield 'Itf'     => [Itf::of('12345678')];
        yield 'Upca'    => [Upca::of('12345678901')];
    }

    /**
     * @param Barcode&SizedBarcode $code
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sizedProvider')]
    public function testIntrinsicWidthIsNullBeforeWithModuleSize(Barcode&SizedBarcode $code): void
    {
        self::assertNull($code->intrinsicWidth());
    }

    /**
     * @param Barcode&SizedBarcode $code
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sizedProvider')]
    public function testWithModuleSizeReturnsNewInstance(Barcode&SizedBarcode $code): void
    {
        $sized = $code->withModuleSize(0.3);

        self::assertNotSame($code, $sized);
        self::assertNull($code->intrinsicWidth());
        self::assertNotNull($sized->intrinsicWidth());
    }

    /**
     * @param Barcode&SizedBarcode $code
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sizedProvider')]
    public function testIntrinsicWidthMatchesWidthForModule(Barcode&SizedBarcode $code): void
    {
        $sized = $code->withModuleSize(0.42);
        self::assertSame($code->widthForModule(0.42), $sized->intrinsicWidth());
    }

    /**
     * @param Barcode&SizedBarcode $code
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sizedProvider')]
    public function testWithModuleSizeRejectsZero(Barcode&SizedBarcode $code): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Module size must be positive, got 0');
        $code->withModuleSize(0.0);
    }

    /**
     * @param Barcode&SizedBarcode $code
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sizedProvider')]
    public function testWithModuleSizeRejectsNegative(Barcode&SizedBarcode $code): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Module size must be positive, got -1');
        $code->withModuleSize(-1.0);
    }

    /**
     * @param Barcode&SizedBarcode $code
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sizedProvider')]
    public function testPageBarcodeAcceptsSizedWithoutExplicitWidth(Barcode&SizedBarcode $code): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $sized = $code->withModuleSize(2.0);

        // Should not throw: w is derived from intrinsicWidth(). RIGHT advances
        // the cursor by the intrinsic width (barcode()'s default is NONE).
        $page->barcode($sized, x: 10.0, y: 10.0, h: 30.0, ln: NextPosition::RIGHT);

        self::assertEqualsWithDelta(10.0 + (float) $sized->intrinsicWidth(), $page->getX(), 1e-9);
    }

    public function testPageBarcodeThrowsWhenWMissingAndNotSized(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Barcode width is required');
        $page->barcode(QrCode::of('HELLO'), x: 10.0, y: 10.0);
    }

    /**
     * @param Barcode&SizedBarcode $code
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sizedProvider')]
    public function testPageBarcodeThrowsWhenWMissingAndModuleSizeNotSet(Barcode&SizedBarcode $code): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Barcode width is required');
        $page->barcode($code, x: 10.0, y: 10.0, h: 30.0);
    }
}
