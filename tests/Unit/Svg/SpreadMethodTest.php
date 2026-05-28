<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\SpreadMethod;
use PHPUnit\Framework\TestCase;

final class SpreadMethodTest extends TestCase
{
    public function testEnumValuesMatchSvgKeywords(): void
    {
        self::assertSame('pad', SpreadMethod::PAD->value);
        self::assertSame('reflect', SpreadMethod::REFLECT->value);
        self::assertSame('repeat', SpreadMethod::REPEAT->value);
    }

    public function testTryFromNameMapsRecognisedKeywords(): void
    {
        self::assertSame(SpreadMethod::PAD, SpreadMethod::tryFromName('pad'));
        self::assertSame(SpreadMethod::REFLECT, SpreadMethod::tryFromName('reflect'));
        self::assertSame(SpreadMethod::REPEAT, SpreadMethod::tryFromName('repeat'));
    }

    public function testTryFromNameDefaultsToPadOnNullEmptyOrUnknown(): void
    {
        self::assertSame(SpreadMethod::PAD, SpreadMethod::tryFromName(null));
        self::assertSame(SpreadMethod::PAD, SpreadMethod::tryFromName(''));
        self::assertSame(SpreadMethod::PAD, SpreadMethod::tryFromName('REPEAT'));
        self::assertSame(SpreadMethod::PAD, SpreadMethod::tryFromName('reflect ' . bin2hex(random_bytes(4))));
    }
}
