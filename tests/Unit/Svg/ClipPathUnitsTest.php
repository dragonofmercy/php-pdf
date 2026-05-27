<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\ClipPathUnits;
use PHPUnit\Framework\TestCase;

final class ClipPathUnitsTest extends TestCase
{
    public function testValues(): void
    {
        self::assertSame('userSpaceOnUse', ClipPathUnits::USER_SPACE_ON_USE->value);
        self::assertSame('objectBoundingBox', ClipPathUnits::OBJECT_BOUNDING_BOX->value);
    }

    public function testFromValue(): void
    {
        self::assertSame(ClipPathUnits::OBJECT_BOUNDING_BOX, ClipPathUnits::from('objectBoundingBox'));
        self::assertNull(ClipPathUnits::tryFrom('nonsense'));
    }
}
