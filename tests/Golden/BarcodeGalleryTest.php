<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Barcode\AztecCode;
use DragonOfMercy\PhpPdf\Barcode\Code128;
use DragonOfMercy\PhpPdf\Barcode\Code39;
use DragonOfMercy\PhpPdf\Barcode\Code93;
use DragonOfMercy\PhpPdf\Barcode\DataMatrix;
use DragonOfMercy\PhpPdf\Barcode\Ean13;
use DragonOfMercy\PhpPdf\Barcode\Ean8;
use DragonOfMercy\PhpPdf\Barcode\ErrorCorrection;
use DragonOfMercy\PhpPdf\Barcode\Itf;
use DragonOfMercy\PhpPdf\Barcode\Pdf417;
use DragonOfMercy\PhpPdf\Barcode\QrCode;
use DragonOfMercy\PhpPdf\Barcode\Upca;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\PageMargins;
use DragonOfMercy\PhpPdf\Unit;

/**
 * Showcase fixture rendering every supported barcode on one A4 page,
 * mirroring the spirit of TCPDF's `E020_barcodes.pdf` example.
 *
 * Exercises the full barcode dispatch through Page::barcode() in a realistic
 * multi-format layout, with the same constructors and styling helpers a user
 * would call.
 */
final class BarcodeGalleryTest extends AbstractBarcodeGoldenTest
{
    protected function fixturePath(): string
    {
        return __DIR__ . '/fixtures/barcode-gallery.pdf';
    }

    protected function buildPdfBytes(): string
    {
        $doc = new Document(Unit::MM);
        $doc->setMargins(PageMargins::sides(top: 20, right: 20, bottom: 40, left: 20));
        $page = $doc->addPage();

        $page->setFont(Font::helvetica()->bold(), 16);
        $page->text(20, 22, 'phppdf - Barcode Gallery');
        $page->setFont(Font::helvetica(), 9);
        $page->text(20, 28, 'All supported 1D and 2D barcode formats in a single page.');

        $labelX = 20.0;
        $codeX = 70.0;
        $dataX = 140.0;
        $row1dHeight = 18.0;
        $row1dBarcodeWidth = 60.0;
        $row1dBarcodeHeight = 10.0;
        $y = 40.0;

        $page->setFont(Font::helvetica()->bold(), 9);
        $page->text($labelX, $y - 3, 'Format');
        $page->text($codeX, $y - 3, 'Barcode');
        $page->text($dataX, $y - 3, 'Encoded data');

        $rows1d = [
            ['EAN-13', Ean13::of('978013110362'), '978013110362'],
            ['EAN-8', Ean8::of('1234567'), '1234567'],
            ['Code 128', Code128::of('SHIP-2026-001'), 'SHIP-2026-001'],
            ['UPC-A', Upca::of('03600029145'), '03600029145'],
            ['Code 39', Code39::of('CODE 39 ABC'), 'CODE 39 ABC'],
            ['Code 93', Code93::of('CODE-93-XYZ'), 'CODE-93-XYZ'],
            ['ITF', Itf::of('1234567890'), '1234567890'],
        ];

        foreach ($rows1d as [$label, $code, $data]) {
            $page->setFont(Font::helvetica()->bold(), 10);
            $page->text($labelX, $y + 6, $label);
            $page->barcode($code, x: $codeX, y: $y, w: $row1dBarcodeWidth, h: $row1dBarcodeHeight);
            $page->setFont(Font::courier(), 9);
            $page->text($dataX, $y + 6, $data);
            $y += $row1dHeight;
        }

        $y += 6.0;
        $page->setFont(Font::helvetica()->bold(), 10);
        $page->text($labelX, $y, '2D codes');
        $y += 6.0;

        $rows2d = [
            ['QR Code (M)', QrCode::of('https://example.com/product/SKU-2026')],
            ['QR Code (H)', QrCode::of('phppdf', ErrorCorrection::H)],
            ['Aztec',       AztecCode::of('https://example.com')],
            ['DataMatrix',  DataMatrix::of('https://example.com/dm')],
        ];

        $code2dSize = 28.0;
        $col2dX = [$labelX, 65.0, 110.0, 155.0];

        foreach ($rows2d as $i => [$label, $code]) {
            $page->setFont(Font::helvetica()->bold(), 9);
            $page->text($col2dX[$i], $y, $label);
            $page->barcode($code, x: $col2dX[$i], y: $y + 2, w: $code2dSize);
        }

        $y += $code2dSize + 12.0;
        $page->setFont(Font::helvetica()->bold(), 9);
        $page->text($labelX, $y, 'PDF417');
        $page->barcode(Pdf417::of('phppdf PDF417'), x: $labelX, y: $y + 4, w: 80.0);

        return $doc->output();
    }
}
