<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Barcode\AztecCode;
use DragonOfMercy\PhpPdf\Barcode\AztecEc;
use DragonOfMercy\PhpPdf\Barcode\Code128;
use DragonOfMercy\PhpPdf\Barcode\Code39;
use DragonOfMercy\PhpPdf\Barcode\Code93;
use DragonOfMercy\PhpPdf\Barcode\Ean13;
use DragonOfMercy\PhpPdf\Barcode\Ean8;
use DragonOfMercy\PhpPdf\Barcode\ErrorCorrection;
use DragonOfMercy\PhpPdf\Barcode\Itf;
use DragonOfMercy\PhpPdf\Barcode\QrCode;
use DragonOfMercy\PhpPdf\Barcode\Upca;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
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
        $page = $doc->addPage();

        $page->setFont(Font::helvetica()->bold(), 16);
        $page->text(20, 22, 'phppdf - Barcode Gallery');
        $page->setFont(Font::helvetica(), 9);
        $page->text(20, 28, 'All supported 1D and 2D barcode formats in a single page.');

        $labelX = 20.0;
        $codeX = 70.0;
        $dataX = 140.0;
        $row1dHeight = 22.0;
        $row1dBarcodeWidth = 60.0;
        $row1dBarcodeHeight = 12.0;
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
            $page->text($labelX, $y + 7, $label);
            $page->barcode($code, x: $codeX, y: $y, w: $row1dBarcodeWidth, h: $row1dBarcodeHeight);
            $page->setFont(Font::courier(), 9);
            $page->text($dataX, $y + 7, $data);
            $y += $row1dHeight;
        }

        $y += 6.0;
        $page->setFont(Font::helvetica()->bold(), 10);
        $page->text($labelX, $y, '2D codes');
        $y += 6.0;

        $rows2d = [
            [
                'QR Code (M)',
                QrCode::of('https://example.com/product/SKU-2026'),
                'https://example.com/product/SKU-2026',
            ],
            [
                'QR Code (H)',
                QrCode::of('phppdf', ErrorCorrection::H),
                'phppdf (EC=H, 30%)',
            ],
            [
                'Aztec (MEDIUM)',
                AztecCode::of('https://example.com'),
                'https://example.com',
            ],
            [
                'Aztec (HIGH)',
                AztecCode::of('M1DOE/JOHN  EABCDEF DTWJFK', AztecEc::HIGH),
                'boarding payload, EC=H',
            ],
        ];

        $code2dSize = 28.0;
        $code2dGap = 6.0;
        $code2dRowHeight = $code2dSize + $code2dGap;

        foreach ($rows2d as [$label, $code, $data]) {
            $page->setFont(Font::helvetica()->bold(), 10);
            $page->text($labelX, $y + 14, $label);
            $page->barcode($code, x: $codeX, y: $y, w: $code2dSize);
            $page->setFont(Font::courier(), 9);
            $page->text($dataX, $y + 14, $data);
            $y += $code2dRowHeight;
        }

        return $doc->output();
    }
}
