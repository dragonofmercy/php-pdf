<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Marker;

use DragonOfMercy\PhpPdf\Svg\Marker\MarkerUnits;
use PHPUnit\Framework\TestCase;

final class MarkerUnitsTest extends TestCase
{
    public function testValues(): void
    {
        self::assertSame('strokeWidth', MarkerUnits::STROKE_WIDTH->value);
        self::assertSame('userSpaceOnUse', MarkerUnits::USER_SPACE_ON_USE->value);
    }

    public function testTryFromNameDefaultsToStrokeWidth(): void
    {
        self::assertSame(MarkerUnits::STROKE_WIDTH, MarkerUnits::tryFromName(null));
        self::assertSame(MarkerUnits::STROKE_WIDTH, MarkerUnits::tryFromName(''));
        self::assertSame(MarkerUnits::STROKE_WIDTH, MarkerUnits::tryFromName('garbage'));
        self::assertSame(MarkerUnits::STROKE_WIDTH, MarkerUnits::tryFromName('strokeWidth'));
        self::assertSame(MarkerUnits::USER_SPACE_ON_USE, MarkerUnits::tryFromName('userSpaceOnUse'));
    }
}
