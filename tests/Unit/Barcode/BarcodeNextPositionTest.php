<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\{Code128, QrCode};
use DragonOfMercy\PhpPdf\{Document, NextPosition, Unit};
use PHPUnit\Framework\TestCase;

/**
 * Covers Page::barcode()'s NextPosition cursor handling: the default leaves the
 * cursor untouched (NONE), and RIGHT / NEWLINE / BELOW move it using the same
 * Cursor::advance() machinery as cell(). Visual width/height follow orientation
 * (a vertical 1D code is rotated a quarter turn), and a square 2D code with a
 * null h resolves its height to w.
 */
final class BarcodeNextPositionTest extends TestCase
{
    private function freshPage(): \DragonOfMercy\PhpPdf\Page
    {
        $doc = new Document(Unit::PT);
        return $doc->addPage();
    }

    public function testDefaultLeavesCursorUntouched(): void
    {
        $page = $this->freshPage();
        $page->setXY(5.0, 7.0);

        $page->barcode(Code128::of('HELLO'), x: 10.0, y: 20.0, w: 50.0, h: 30.0);

        self::assertEqualsWithDelta(5.0, $page->getX(), 1e-9);
        self::assertEqualsWithDelta(7.0, $page->getY(), 1e-9);
    }

    public function testExplicitNoneLeavesCursorUntouched(): void
    {
        $page = $this->freshPage();
        $page->setXY(5.0, 7.0);

        $page->barcode(Code128::of('HELLO'), x: 10.0, y: 20.0, w: 50.0, h: 30.0, ln: NextPosition::NONE);

        self::assertEqualsWithDelta(5.0, $page->getX(), 1e-9);
        self::assertEqualsWithDelta(7.0, $page->getY(), 1e-9);
    }

    public function testRightAdvancesByWidthHorizontal(): void
    {
        $page = $this->freshPage();

        $page->barcode(Code128::of('HELLO'), x: 10.0, y: 20.0, w: 50.0, h: 30.0, ln: NextPosition::RIGHT);

        // Visual width = w; y unchanged.
        self::assertEqualsWithDelta(60.0, $page->getX(), 1e-9);
        self::assertEqualsWithDelta(20.0, $page->getY(), 1e-9);
    }

    public function testRightAdvancesByHeightVertical(): void
    {
        $page = $this->freshPage();

        $page->barcode(Code128::of('HELLO')->vertical(), x: 10.0, y: 20.0, w: 50.0, h: 30.0, ln: NextPosition::RIGHT);

        // A vertical 1D code is rotated a quarter turn: visual width = h.
        self::assertEqualsWithDelta(40.0, $page->getX(), 1e-9);
        self::assertEqualsWithDelta(20.0, $page->getY(), 1e-9);
    }

    public function testBelowAdvancesByHeightHorizontal(): void
    {
        $page = $this->freshPage();

        $page->barcode(Code128::of('HELLO'), x: 10.0, y: 20.0, w: 50.0, h: 30.0, ln: NextPosition::BELOW);

        // x stays at the left edge; y advances by the visual height (h).
        self::assertEqualsWithDelta(10.0, $page->getX(), 1e-9);
        self::assertEqualsWithDelta(50.0, $page->getY(), 1e-9);
    }

    public function testBelowAdvancesByWidthVertical(): void
    {
        $page = $this->freshPage();

        $page->barcode(Code128::of('HELLO')->vertical(), x: 10.0, y: 20.0, w: 50.0, h: 30.0, ln: NextPosition::BELOW);

        // Vertical: visual height = w.
        self::assertEqualsWithDelta(10.0, $page->getX(), 1e-9);
        self::assertEqualsWithDelta(70.0, $page->getY(), 1e-9);
    }

    public function testNewlineReturnsToRowStartAndAdvancesY(): void
    {
        $page = $this->freshPage();

        $page->barcode(Code128::of('HELLO'), x: 10.0, y: 20.0, w: 50.0, h: 30.0, ln: NextPosition::NEWLINE);

        // Explicit x resets the row-start anchor (mirrors cell()): NEWLINE
        // returns x there and advances y by the visual height.
        self::assertEqualsWithDelta(10.0, $page->getX(), 1e-9);
        self::assertEqualsWithDelta(50.0, $page->getY(), 1e-9);
    }

    public function testTwoDimensionalNullHeightResolvesHeightToWidth(): void
    {
        $page = $this->freshPage();

        // QR is square; h omitted. BELOW must advance y by w (resolved height).
        $page->barcode(QrCode::of('HELLO'), x: 10.0, y: 20.0, w: 40.0, ln: NextPosition::BELOW);

        self::assertEqualsWithDelta(10.0, $page->getX(), 1e-9);
        self::assertEqualsWithDelta(60.0, $page->getY(), 1e-9);
    }

    public function testTwoDimensionalRightAdvancesByWidth(): void
    {
        $page = $this->freshPage();

        $page->barcode(QrCode::of('HELLO'), x: 10.0, y: 20.0, w: 40.0, ln: NextPosition::RIGHT);

        self::assertEqualsWithDelta(50.0, $page->getX(), 1e-9);
        self::assertEqualsWithDelta(20.0, $page->getY(), 1e-9);
    }
}
