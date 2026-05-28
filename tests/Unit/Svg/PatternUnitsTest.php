<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\PatternUnits;
use PHPUnit\Framework\TestCase;

final class PatternUnitsTest extends TestCase
{
    public function testEnumValuesMatchSvgKeywords(): void
    {
        self::assertSame('userSpaceOnUse', PatternUnits::USER_SPACE_ON_USE->value);
        self::assertSame('objectBoundingBox', PatternUnits::OBJECT_BOUNDING_BOX->value);
    }

    public function testTryFromNameDefaultsToObjectBoundingBox(): void
    {
        // SVG spec default for patternUnits is objectBoundingBox.
        self::assertSame(PatternUnits::OBJECT_BOUNDING_BOX, PatternUnits::tryFromName(null));
        self::assertSame(PatternUnits::OBJECT_BOUNDING_BOX, PatternUnits::tryFromName(''));
        self::assertSame(PatternUnits::OBJECT_BOUNDING_BOX, PatternUnits::tryFromName('garbage'));
        self::assertSame(PatternUnits::USER_SPACE_ON_USE, PatternUnits::tryFromName('userSpaceOnUse'));
        self::assertSame(PatternUnits::OBJECT_BOUNDING_BOX, PatternUnits::tryFromName('objectBoundingBox'));
    }
}
