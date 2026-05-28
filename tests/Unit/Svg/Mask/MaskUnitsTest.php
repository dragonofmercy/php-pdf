<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Mask;

use DragonOfMercy\PhpPdf\Svg\Mask\MaskUnits;
use PHPUnit\Framework\TestCase;

final class MaskUnitsTest extends TestCase
{
    public function testValues(): void
    {
        self::assertSame('objectBoundingBox', MaskUnits::OBJECT_BOUNDING_BOX->value);
        self::assertSame('userSpaceOnUse', MaskUnits::USER_SPACE_ON_USE->value);
    }

    public function testTryFromNameDefaultsToObjectBoundingBox(): void
    {
        self::assertSame(MaskUnits::OBJECT_BOUNDING_BOX, MaskUnits::tryFromName(null));
        self::assertSame(MaskUnits::OBJECT_BOUNDING_BOX, MaskUnits::tryFromName(''));
        self::assertSame(MaskUnits::OBJECT_BOUNDING_BOX, MaskUnits::tryFromName('garbage'));
        self::assertSame(MaskUnits::USER_SPACE_ON_USE, MaskUnits::tryFromName('userSpaceOnUse'));
        self::assertSame(MaskUnits::OBJECT_BOUNDING_BOX, MaskUnits::tryFromName('objectBoundingBox'));
    }

    public function testTryFromContentNameDefaultsToUserSpaceOnUse(): void
    {
        // maskContentUnits default in SVG spec is userSpaceOnUse (opposite of maskUnits).
        self::assertSame(MaskUnits::USER_SPACE_ON_USE, MaskUnits::tryFromContentName(null));
        self::assertSame(MaskUnits::USER_SPACE_ON_USE, MaskUnits::tryFromContentName(''));
        self::assertSame(MaskUnits::USER_SPACE_ON_USE, MaskUnits::tryFromContentName('garbage'));
        self::assertSame(MaskUnits::USER_SPACE_ON_USE, MaskUnits::tryFromContentName('userSpaceOnUse'));
        self::assertSame(MaskUnits::OBJECT_BOUNDING_BOX, MaskUnits::tryFromContentName('objectBoundingBox'));
    }
}
