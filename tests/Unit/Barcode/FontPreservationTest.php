<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\{Barcode, Code128, Code39, Code93, Ean13, Ean8, Itf, Upca};
use DragonOfMercy\PhpPdf\{Document, Font, Unit};
use PHPUnit\Framework\TestCase;

/**
 * Each 1D barcode draws its human-readable text via Page::setFont(Helvetica, $size).
 * Without an explicit font-state restore, the page's textState would leak that
 * Helvetica + barcode font-size into the caller's subsequent text() calls, even
 * though q/Q wrap the graphics. This test asserts that the page's font state
 * is identical before and after barcode() returns.
 */
final class FontPreservationTest extends TestCase
{
    /**
     * @return iterable<string, array{0: Barcode}>
     */
    public static function barcodeProvider(): iterable
    {
        yield 'Itf'     => [Itf::of('12345678')];
        yield 'Code39'  => [Code39::of('HELLO')];
        yield 'Code93'  => [Code93::of('HELLO')];
        yield 'Code128' => [Code128::of('HELLO')];
        yield 'Ean8'    => [Ean8::of('1234567')];
        yield 'Ean13'   => [Ean13::of('123456789012')];
        yield 'Upca'    => [Upca::of('12345678901')];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('barcodeProvider')]
    public function testBarcodeRestoresPageFontStateOnReturn(Barcode $code): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(Font::times(), 14.0);

        $page->barcode($code, x: 10.0, y: 10.0, w: 200.0, h: 30.0);

        self::assertEquals(Font::times(), $page->getFont());
        self::assertSame(14.0, $page->getFontSize());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('barcodeProvider')]
    public function testSubsequentTextUsesRestoredFontNotBarcodeHelvetica(Barcode $code): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(Font::courier()->bold(), 18.0);

        $page->barcode($code, x: 10.0, y: 10.0, w: 200.0, h: 30.0);
        $page->text(10.0, 200.0, 'after');

        // The /Font dict on the page resources lists every font referenced by
        // the content stream. With the restore in place, "after" emits an /F<i>
        // bound to Courier-Bold (set originally), proving textState was not
        // clobbered by the barcode's internal setFont(Helvetica).
        $pdf = $doc->output();
        self::assertStringContainsString('/BaseFont /Courier-Bold', $pdf);
    }
}
