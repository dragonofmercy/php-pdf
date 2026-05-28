<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\TextAnchor;
use PHPUnit\Framework\TestCase;

final class TextAnchorTest extends TestCase
{
    public function testValuesMatchSvgTextAnchor(): void
    {
        self::assertSame('start', TextAnchor::START->value);
        self::assertSame('middle', TextAnchor::MIDDLE->value);
        self::assertSame('end', TextAnchor::END->value);
    }
}
