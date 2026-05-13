<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Image\SvgMetadata;
use PHPUnit\Framework\TestCase;

final class SvgMetadataTest extends TestCase
{
    public function testConstructorStoresIntrinsicDimensions(): void
    {
        $meta = new SvgMetadata(intrinsicWidth: 24.5, intrinsicHeight: 24.5);
        self::assertSame(24.5, $meta->intrinsicWidth);
        self::assertSame(24.5, $meta->intrinsicHeight);
    }
}
