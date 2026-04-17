<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Page;

use PhpPdf\LineCap;
use PhpPdf\LineJoin;
use PhpPdf\Page\Operators;
use PHPUnit\Framework\TestCase;

final class OperatorsTest extends TestCase
{
    public function testMoveTo(): void
    {
        self::assertSame("10 20 m\n", Operators::moveTo(10, 20));
    }

    public function testLineTo(): void
    {
        self::assertSame("30 40 l\n", Operators::lineTo(30, 40));
    }

    public function testCurveTo(): void
    {
        self::assertSame("1 2 3 4 5 6 c\n", Operators::curveTo(1, 2, 3, 4, 5, 6));
    }

    public function testRectangle(): void
    {
        self::assertSame("10 20 100 50 re\n", Operators::rectangle(10, 20, 100, 50));
    }

    public function testClosePath(): void
    {
        self::assertSame("h\n", Operators::closePath());
    }

    public function testStroke(): void
    {
        self::assertSame("S\n", Operators::stroke());
    }

    public function testFill(): void
    {
        self::assertSame("f\n", Operators::fill());
    }

    public function testFillStroke(): void
    {
        self::assertSame("B\n", Operators::fillStroke());
    }

    public function testSetLineWidth(): void
    {
        self::assertSame("2.5 w\n", Operators::setLineWidth(2.5));
    }

    public function testSetLineCap(): void
    {
        self::assertSame("1 J\n", Operators::setLineCap(LineCap::ROUND));
    }

    public function testSetLineJoin(): void
    {
        self::assertSame("2 j\n", Operators::setLineJoin(LineJoin::BEVEL));
    }

    public function testSetDashPattern(): void
    {
        self::assertSame("[3 2] 0 d\n", Operators::setDashPattern([3.0, 2.0], 0.0));
        self::assertSame("[5 1.5 2] 1 d\n", Operators::setDashPattern([5.0, 1.5, 2.0], 1.0));
        self::assertSame("[] 0 d\n", Operators::setDashPattern([], 0.0));
    }

    public function testConcatMatrix(): void
    {
        self::assertSame("1 0 0 -1 0 841.89 cm\n", Operators::concatMatrix(1, 0, 0, -1, 0, 841.89));
    }

    public function testSaveAndRestore(): void
    {
        self::assertSame("q\n", Operators::saveState());
        self::assertSame("Q\n", Operators::restoreState());
    }

    public function testTranslate(): void
    {
        self::assertSame("1 0 0 1 10 20 cm\n", Operators::translate(10, 20));
    }

    public function testScale(): void
    {
        self::assertSame("2 0 0 3 0 0 cm\n", Operators::scale(2, 3));
    }

    public function testRotateDegreesIsConvertedToRadians(): void
    {
        self::assertSame("0 1 -1 0 0 0 cm\n", Operators::rotate(90));
    }
}
