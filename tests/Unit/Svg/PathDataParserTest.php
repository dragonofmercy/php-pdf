<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Svg\PathCommand\Arc;
use DragonOfMercy\PhpPdf\Svg\PathCommand\ClosePath;
use DragonOfMercy\PhpPdf\Svg\PathCommand\CubicBezier;
use DragonOfMercy\PhpPdf\Svg\PathCommand\LineTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\MoveTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\QuadraticBezier;
use DragonOfMercy\PhpPdf\Svg\PathDataParser;
use PHPUnit\Framework\TestCase;

final class PathDataParserTest extends TestCase
{
    public function testEmptyStringYieldsNoCommands(): void
    {
        self::assertSame([], PathDataParser::parse(''));
        self::assertSame([], PathDataParser::parse('   '));
    }

    public function testAbsoluteMoveToAndLineTo(): void
    {
        $cmds = PathDataParser::parse('M 10 20 L 30 40');
        self::assertCount(2, $cmds);
        self::assertInstanceOf(MoveTo::class, $cmds[0]);
        self::assertSame(10.0, $cmds[0]->x);
        self::assertSame(20.0, $cmds[0]->y);
        self::assertInstanceOf(LineTo::class, $cmds[1]);
        self::assertSame(30.0, $cmds[1]->x);
    }

    public function testRelativeMoveToResolvesAbsolute(): void
    {
        $cmds = PathDataParser::parse('M 10 20 m 5 5');
        self::assertCount(2, $cmds);
        self::assertInstanceOf(MoveTo::class, $cmds[0]);
        self::assertSame(10.0, $cmds[0]->x);
        self::assertInstanceOf(MoveTo::class, $cmds[1]);
        self::assertSame(15.0, $cmds[1]->x);
        self::assertSame(25.0, $cmds[1]->y);
    }

    public function testMoveToWithExtraPairsBecomesImplicitLineTo(): void
    {
        // M followed by additional pairs = M then L for each subsequent pair.
        $cmds = PathDataParser::parse('M 0 0 1 1 2 2');
        self::assertCount(3, $cmds);
        self::assertInstanceOf(MoveTo::class, $cmds[0]);
        self::assertInstanceOf(LineTo::class, $cmds[1]);
        self::assertSame(1.0, $cmds[1]->x);
        self::assertInstanceOf(LineTo::class, $cmds[2]);
        self::assertSame(2.0, $cmds[2]->x);
    }

    public function testRelativeMoveToWithExtraPairsBecomesRelativeLineTo(): void
    {
        // m 0 0 1 1 1 1 = M(0,0), L(1,1), L(2,2)
        $cmds = PathDataParser::parse('m 0 0 1 1 1 1');
        self::assertCount(3, $cmds);
        self::assertInstanceOf(MoveTo::class, $cmds[0]);
        self::assertSame(0.0, $cmds[0]->x);
        self::assertInstanceOf(LineTo::class, $cmds[1]);
        self::assertSame(1.0, $cmds[1]->x);
        self::assertInstanceOf(LineTo::class, $cmds[2]);
        self::assertSame(2.0, $cmds[2]->x);
    }

    public function testHorizontalLineToExpandsToLineTo(): void
    {
        $cmds = PathDataParser::parse('M 10 20 H 50');
        self::assertCount(2, $cmds);
        self::assertInstanceOf(LineTo::class, $cmds[1]);
        self::assertSame(50.0, $cmds[1]->x);
        self::assertSame(20.0, $cmds[1]->y); // y unchanged
    }

    public function testVerticalLineToExpandsToLineTo(): void
    {
        $cmds = PathDataParser::parse('M 10 20 V 50');
        self::assertCount(2, $cmds);
        self::assertInstanceOf(LineTo::class, $cmds[1]);
        self::assertSame(10.0, $cmds[1]->x); // x unchanged
        self::assertSame(50.0, $cmds[1]->y);
    }

    public function testRelativeHorizontalLineToAdds(): void
    {
        $cmds = PathDataParser::parse('M 10 20 h 5');
        self::assertInstanceOf(LineTo::class, $cmds[1]);
        self::assertSame(15.0, $cmds[1]->x);
    }

    public function testAbsoluteCubicBezier(): void
    {
        $cmds = PathDataParser::parse('M 0 0 C 10 0 20 10 30 10');
        self::assertCount(2, $cmds);
        self::assertInstanceOf(CubicBezier::class, $cmds[1]);
        /** @var CubicBezier $c */
        $c = $cmds[1];
        self::assertSame(10.0, $c->c1x);
        self::assertSame(0.0, $c->c1y);
        self::assertSame(20.0, $c->c2x);
        self::assertSame(10.0, $c->c2y);
        self::assertSame(30.0, $c->x);
        self::assertSame(10.0, $c->y);
    }

    public function testSmoothCubicReflectsPreviousControlPoint(): void
    {
        // M 0 0 C 1 0 2 1 3 1 S 5 2 6 1
        // After C, c2 = (2, 1), end = (3, 1). Reflect c2 about end: (2*3-2, 2*1-1) = (4, 1).
        // S expanded becomes C 4 1 5 2 6 1.
        $cmds = PathDataParser::parse('M 0 0 C 1 0 2 1 3 1 S 5 2 6 1');
        self::assertCount(3, $cmds);
        /** @var CubicBezier $s */
        $s = $cmds[2];
        self::assertInstanceOf(CubicBezier::class, $s);
        self::assertSame(4.0, $s->c1x);
        self::assertSame(1.0, $s->c1y);
        self::assertSame(5.0, $s->c2x);
        self::assertSame(6.0, $s->x);
    }

    public function testSmoothCubicWithoutPrevCubicUsesCurrentPoint(): void
    {
        // S without prev C/S: c1 = current point.
        $cmds = PathDataParser::parse('M 10 10 S 20 20 30 30');
        self::assertCount(2, $cmds);
        /** @var CubicBezier $s */
        $s = $cmds[1];
        self::assertSame(10.0, $s->c1x);
        self::assertSame(10.0, $s->c1y);
    }

    public function testQuadraticBezier(): void
    {
        $cmds = PathDataParser::parse('M 0 0 Q 5 10 10 0');
        self::assertCount(2, $cmds);
        self::assertInstanceOf(QuadraticBezier::class, $cmds[1]);
        /** @var QuadraticBezier $q */
        $q = $cmds[1];
        self::assertSame(5.0, $q->cx);
        self::assertSame(10.0, $q->cy);
        self::assertSame(10.0, $q->x);
    }

    public function testSmoothQuadraticReflects(): void
    {
        // M 0 0 Q 5 10 10 0 T 20 0
        // After Q, control = (5, 10), end = (10, 0). Reflect about end: (15, -10).
        // T expanded becomes Q 15 -10 20 0.
        $cmds = PathDataParser::parse('M 0 0 Q 5 10 10 0 T 20 0');
        self::assertCount(3, $cmds);
        /** @var QuadraticBezier $t */
        $t = $cmds[2];
        self::assertInstanceOf(QuadraticBezier::class, $t);
        self::assertSame(15.0, $t->cx);
        self::assertSame(-10.0, $t->cy);
    }

    public function testArcKeptAsArcCommand(): void
    {
        $cmds = PathDataParser::parse('M 0 0 A 5 5 0 0 1 10 0');
        self::assertCount(2, $cmds);
        self::assertInstanceOf(Arc::class, $cmds[1]);
        /** @var Arc $a */
        $a = $cmds[1];
        self::assertSame(5.0, $a->rx);
        self::assertSame(5.0, $a->ry);
        self::assertSame(0.0, $a->xAxisRotationDeg);
        self::assertFalse($a->largeArc);
        self::assertTrue($a->sweep);
        self::assertSame(10.0, $a->x);
    }

    public function testClosePath(): void
    {
        $cmds = PathDataParser::parse('M 0 0 L 10 0 L 10 10 Z');
        self::assertCount(4, $cmds);
        self::assertInstanceOf(ClosePath::class, $cmds[3]);
    }

    public function testClosePathSetsCurrentToSubpathStart(): void
    {
        // After Z, cur should reset to the M start so a subsequent relative cmd uses it.
        $cmds = PathDataParser::parse('M 10 10 L 20 20 Z l 5 0');
        self::assertCount(4, $cmds);
        /** @var LineTo $after */
        $after = $cmds[3];
        self::assertInstanceOf(LineTo::class, $after);
        self::assertSame(15.0, $after->x);
        self::assertSame(10.0, $after->y);
    }

    public function testNegativeNumbersWithoutSeparator(): void
    {
        // "M10-5" tokenizes as M, 10, -5.
        $cmds = PathDataParser::parse('M10-5L20 30');
        self::assertInstanceOf(MoveTo::class, $cmds[0]);
        self::assertSame(10.0, $cmds[0]->x);
        self::assertSame(-5.0, $cmds[0]->y);
        self::assertInstanceOf(LineTo::class, $cmds[1]);
        self::assertSame(20.0, $cmds[1]->x);
    }

    public function testDecimalsWithoutSeparator(): void
    {
        // "M1.5.5" tokenizes as M, 1.5, .5.
        $cmds = PathDataParser::parse('M1.5.5');
        self::assertInstanceOf(MoveTo::class, $cmds[0]);
        self::assertSame(1.5, $cmds[0]->x);
        self::assertSame(0.5, $cmds[0]->y);
    }

    public function testCommaSeparators(): void
    {
        $cmds = PathDataParser::parse('M0,0 L10,10');
        self::assertInstanceOf(LineTo::class, $cmds[1]);
        self::assertSame(10.0, $cmds[1]->x);
    }

    public function testScientificNotation(): void
    {
        $cmds = PathDataParser::parse('M 1e2 2E-1');
        self::assertInstanceOf(MoveTo::class, $cmds[0]);
        self::assertSame(100.0, $cmds[0]->x);
        self::assertEqualsWithDelta(0.2, $cmds[0]->y, 1e-9);
    }

    public function testUnknownCommandCutsParseSilently(): void
    {
        // Per spec error handling: unknown command -> stop, keep what precedes.
        $cmds = PathDataParser::parse('M 0 0 X 10 10');
        self::assertCount(1, $cmds);
        self::assertInstanceOf(MoveTo::class, $cmds[0]);
    }

    public function testFirstCommandNotMoveToThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Path data must begin with M or m');
        PathDataParser::parse('L 0 0');
    }

    public function testInsufficientParamsThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Path command C expects 6 numbers, got 5');
        PathDataParser::parse('M 0 0 C 1 0 2 1 3');
    }
}
