<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\SvgMatrix;
use PHPUnit\Framework\TestCase;

final class SvgMatrixTest extends TestCase
{
    public function testIdentity(): void
    {
        $m = SvgMatrix::identity();
        self::assertEqualsWithDelta([1.0, 0.0, 0.0, 1.0, 0.0, 0.0], $m->toArray(), 1e-9);
    }

    public function testTranslate(): void
    {
        $m = SvgMatrix::translate(10.0, 20.0);
        self::assertEqualsWithDelta([1.0, 0.0, 0.0, 1.0, 10.0, 20.0], $m->toArray(), 1e-9);
    }

    public function testScaleUniform(): void
    {
        $m = SvgMatrix::scale(2.5);
        self::assertEqualsWithDelta([2.5, 0.0, 0.0, 2.5, 0.0, 0.0], $m->toArray(), 1e-9);
    }

    public function testScaleNonUniform(): void
    {
        $m = SvgMatrix::scale(2.0, 3.0);
        self::assertEqualsWithDelta([2.0, 0.0, 0.0, 3.0, 0.0, 0.0], $m->toArray(), 1e-9);
    }

    public function testRotate90Degrees(): void
    {
        $m = SvgMatrix::rotate(90.0);
        // matrix [a b c d e f] applied to (x,y) -> (a*x + c*y + e, b*x + d*y + f)
        self::assertEqualsWithDelta([0.0, 1.0, -1.0, 0.0, 0.0, 0.0], $m->toArray(), 1e-9);
    }

    public function testComposeAppliesRightThenLeft(): void
    {
        // T(10,0) * S(2) applied to (1,0): S(1,0) = (2,0); T(2,0) = (12,0).
        $compose = SvgMatrix::translate(10.0, 0.0)->compose(SvgMatrix::scale(2.0));
        [$tx, $ty] = $compose->apply(1.0, 0.0);
        self::assertEqualsWithDelta(12.0, $tx, 1e-9);
        self::assertEqualsWithDelta(0.0, $ty, 1e-9);
    }

    public function testApplyPoint(): void
    {
        $m = new SvgMatrix(2.0, 0.0, 0.0, 3.0, 5.0, 7.0);
        [$x, $y] = $m->apply(1.0, 1.0);
        self::assertSame(7.0, $x);
        self::assertSame(10.0, $y);
    }

    public function testIsIdentityTrueForIdentity(): void
    {
        self::assertTrue(SvgMatrix::identity()->isIdentity());
    }

    public function testIsIdentityFalseForTranslate(): void
    {
        self::assertFalse(SvgMatrix::translate(1.0, 0.0)->isIdentity());
    }
}
