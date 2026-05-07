<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class UnitTest extends TestCase
{
    public function testPtIsIdentity(): void
    {
        self::assertSame(42.0, Unit::PT->toPoints(42.0));
        self::assertSame(42.0, Unit::PT->fromPoints(42.0));
    }

    public function testMmRoundTrip(): void
    {
        $mm = 123.456;
        self::assertEqualsWithDelta($mm, Unit::MM->fromPoints(Unit::MM->toPoints($mm)), 1e-9);
    }

    public function testKnownConversions(): void
    {
        // 25.4 mm == 1 inch == 72 pt
        self::assertEqualsWithDelta(72.0, Unit::MM->toPoints(25.4), 1e-9);
        self::assertEqualsWithDelta(25.4, Unit::MM->fromPoints(72.0), 1e-9);

        // A4: 210 x 297 mm
        self::assertEqualsWithDelta(595.275591, Unit::MM->toPoints(210.0), 1e-6);
        self::assertEqualsWithDelta(841.889764, Unit::MM->toPoints(297.0), 1e-6);
    }
}
