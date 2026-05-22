<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\NextPosition;
use DragonOfMercy\PhpPdf\Page\Cursor;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class CursorTest extends TestCase
{
    public function testGetXThrowsWhenUnset(): void
    {
        $this->expectException(PdfException::class);
        (new Cursor(Unit::PT))->getX();
    }

    public function testSetXYRoundTripsInPoints(): void
    {
        $c = new Cursor(Unit::PT);
        $c->setXY(10.0, 20.0);
        self::assertSame(10.0, $c->getX());
        self::assertSame(20.0, $c->getY());
        self::assertSame(10.0, $c->xPt());
        self::assertSame(20.0, $c->yPt());
    }

    public function testAdvanceRightMovesXByEffectiveWidth(): void
    {
        $c = new Cursor(Unit::PT);
        $c->advance(NextPosition::RIGHT, 10.0, 20.0, 30.0, 8.0);
        self::assertSame(40.0, $c->xPt());
        self::assertSame(20.0, $c->yPt());
    }

    public function testAdvanceNewlineReturnsToRowStartAndDropsByHeight(): void
    {
        $c = new Cursor(Unit::PT);
        $c->setXY(10.0, 20.0);
        $c->advance(NextPosition::NEWLINE, 50.0, 20.0, 30.0, 8.0);
        self::assertSame(10.0, $c->xPt());
        self::assertSame(28.0, $c->yPt());
    }

    public function testAdvanceBelowKeepsXAndDropsByHeight(): void
    {
        $c = new Cursor(Unit::PT);
        $c->advance(NextPosition::BELOW, 10.0, 20.0, 30.0, 8.0);
        self::assertSame(10.0, $c->xPt());
        self::assertSame(28.0, $c->yPt());
    }

    public function testAdvanceNoneLeavesCursorUnchanged(): void
    {
        $c = new Cursor(Unit::PT);
        $c->setXY(5.0, 5.0);
        $c->advance(NextPosition::NONE, 50.0, 60.0, 30.0, 8.0);
        self::assertSame(5.0, $c->xPt());
        self::assertSame(5.0, $c->yPt());
    }
}
