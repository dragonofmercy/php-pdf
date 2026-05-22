<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Barcode\Code128;
use DragonOfMercy\PhpPdf\Barcode\QrCode;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class BarcodeOrientationCursorTest extends TestCase
{
    public function testVerticalBarcodeAdvancesCursorByHeight(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();

        // Vertical: visual width is h (=18), so next x should be 10 + 18 = 28.
        $page->barcode(Code128::of('ABC123')->vertical(), x: 10.0, y: 20.0, w: 70.0, h: 18.0);

        self::assertSame(28.0, $page->getX());
    }

    public function testHorizontalBarcodeAdvancesCursorByWidth(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();

        // Horizontal: visual width is w (=70), so next x should be 10 + 70 = 80.
        $page->barcode(Code128::of('ABC123'), x: 10.0, y: 20.0, w: 70.0, h: 18.0);

        self::assertSame(80.0, $page->getX());
    }

    public function testTwoDimensionalBarcodeAdvancesCursorByWidth(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();

        // QrCode is 2D (implements Barcode only, not OrientableBarcode), so the
        // cursor always advances by w regardless of orientation handling.
        $page->barcode(QrCode::of('TEST'), x: 10.0, y: 20.0, w: 40.0, h: 40.0);

        self::assertSame(50.0, $page->getX());
    }
}
