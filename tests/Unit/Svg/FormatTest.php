<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\Format;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testIntegralRendersWithoutDecimal(): void
    {
        self::assertSame('0', Format::num(0.0));
        self::assertSame('1', Format::num(1.0));
        self::assertSame('100', Format::num(100.0));
    }

    public function testNegativeIntegral(): void
    {
        self::assertSame('-5', Format::num(-5.0));
    }

    public function testFractionTrimsTrailingZeros(): void
    {
        self::assertSame('0.5', Format::num(0.5));
        self::assertSame('1.25', Format::num(1.25));
    }

    public function testNegativeFraction(): void
    {
        self::assertSame('-0.5', Format::num(-0.5));
    }

    public function testSmallFractionSixDecimals(): void
    {
        self::assertSame('0.000001', Format::num(0.000001));
    }

    public function testRoundsToSixDecimals(): void
    {
        // number_format rounds to 6 decimals
        self::assertSame('0.333333', Format::num(1.0 / 3.0));
    }
}
