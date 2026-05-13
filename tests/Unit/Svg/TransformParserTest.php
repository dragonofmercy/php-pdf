<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Svg\SvgMatrix;
use DragonOfMercy\PhpPdf\Svg\TransformParser;
use PHPUnit\Framework\TestCase;

final class TransformParserTest extends TestCase
{
    public function testEmptyReturnsNull(): void
    {
        self::assertNull(TransformParser::parse(''));
        self::assertNull(TransformParser::parse('   '));
    }

    public function testMatrix(): void
    {
        $m = TransformParser::parse('matrix(1 0 0 1 10 20)');
        self::assertNotNull($m);
        self::assertEqualsWithDelta([1.0, 0.0, 0.0, 1.0, 10.0, 20.0], $m->toArray(), 1e-9);
    }

    public function testTranslateSingleArg(): void
    {
        $m = TransformParser::parse('translate(10)');
        self::assertNotNull($m);
        self::assertEqualsWithDelta([1.0, 0.0, 0.0, 1.0, 10.0, 0.0], $m->toArray(), 1e-9);
    }

    public function testTranslateTwoArgs(): void
    {
        $m = TransformParser::parse('translate(10, 20)');
        self::assertNotNull($m);
        self::assertEqualsWithDelta([1.0, 0.0, 0.0, 1.0, 10.0, 20.0], $m->toArray(), 1e-9);
    }

    public function testScaleSingleArg(): void
    {
        $m = TransformParser::parse('scale(2)');
        self::assertNotNull($m);
        self::assertEqualsWithDelta([2.0, 0.0, 0.0, 2.0, 0.0, 0.0], $m->toArray(), 1e-9);
    }

    public function testScaleTwoArgs(): void
    {
        $m = TransformParser::parse('scale(2, 3)');
        self::assertNotNull($m);
        self::assertEqualsWithDelta([2.0, 0.0, 0.0, 3.0, 0.0, 0.0], $m->toArray(), 1e-9);
    }

    public function testRotateSingleArg(): void
    {
        $m = TransformParser::parse('rotate(90)');
        self::assertNotNull($m);
        self::assertEqualsWithDelta([0.0, 1.0, -1.0, 0.0, 0.0, 0.0], $m->toArray(), 1e-9);
    }

    public function testRotateAroundCenter(): void
    {
        // rotate(90, 10, 10): rotate 90 around (10, 10).
        $m = TransformParser::parse('rotate(90, 10, 10)');
        self::assertNotNull($m);
        // (0,0) should map to (20, 0) after the rotation around (10,10).
        [$x, $y] = $m->apply(0.0, 0.0);
        self::assertEqualsWithDelta(20.0, $x, 1e-9);
        self::assertEqualsWithDelta(0.0, $y, 1e-9);
    }

    public function testSkewX(): void
    {
        $m = TransformParser::parse('skewX(45)');
        self::assertNotNull($m);
        // skewX(45) maps (1, 0) to (1, 0) but (0, 1) to (1, 1).
        [$x, $y] = $m->apply(0.0, 1.0);
        self::assertEqualsWithDelta(1.0, $x, 1e-9);
        self::assertEqualsWithDelta(1.0, $y, 1e-9);
    }

    public function testSkewY(): void
    {
        $m = TransformParser::parse('skewY(45)');
        self::assertNotNull($m);
        [$x, $y] = $m->apply(1.0, 0.0);
        self::assertEqualsWithDelta(1.0, $x, 1e-9);
        self::assertEqualsWithDelta(1.0, $y, 1e-9);
    }

    public function testCompositionLeftToRight(): void
    {
        // translate(10, 0) then scale(2): (1, 0) -> first translated to (11, 0), then scaled to (22, 0)?
        // Actually with left-to-right semantics: applied as result = T * S, so point P becomes T(S(P)).
        // For (1, 0): S(1, 0) = (2, 0); T(2, 0) = (12, 0).
        $m = TransformParser::parse('translate(10, 0) scale(2)');
        self::assertNotNull($m);
        [$x, $y] = $m->apply(1.0, 0.0);
        self::assertEqualsWithDelta(12.0, $x, 1e-9);
    }

    public function testCommaOrSpaceSeparatedArgs(): void
    {
        $a = TransformParser::parse('translate(10 20)');
        $b = TransformParser::parse('translate(10, 20)');
        $c = TransformParser::parse('translate(10,20)');
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNotNull($c);
        self::assertEqualsWithDelta($a->toArray(), $b->toArray(), 1e-9);
        self::assertEqualsWithDelta($a->toArray(), $c->toArray(), 1e-9);
    }

    public function testUnknownFunctionTruncates(): void
    {
        // First function recognised + applied; rest silently dropped.
        $m = TransformParser::parse('translate(10, 20) skewZ(45) scale(2)');
        self::assertNotNull($m);
        self::assertEqualsWithDelta([1.0, 0.0, 0.0, 1.0, 10.0, 20.0], $m->toArray(), 1e-9);
    }

    public function testMatrixWrongArgCountThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('matrix() expects 6 numbers, got 5');
        TransformParser::parse('matrix(1 0 0 1 10)');
    }

    public function testTranslateWrongArgCountThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('translate() expects 1 or 2 numbers, got 3');
        TransformParser::parse('translate(1, 2, 3)');
    }

    public function testRotateWrongArgCountThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('rotate() expects 1 or 3 numbers, got 2');
        TransformParser::parse('rotate(45, 10)');
    }

    public function testMalformedSyntaxThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Malformed transform attribute: 'translate(1 2'");
        TransformParser::parse('translate(1 2');
    }
}
