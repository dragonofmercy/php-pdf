<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer\Object;

use DragonOfMercy\PhpPdf\Writer\Object\RawValue;
use PHPUnit\Framework\TestCase;

final class RawValueTest extends TestCase
{
    public function testToByteReturnsRawStringUnchanged(): void
    {
        self::assertSame('[0 1 2]', RawValue::of('[0 1 2]')->toBytes());
    }

    public function testEmptyStringPassesThrough(): void
    {
        self::assertSame('', RawValue::of('')->toBytes());
    }

    public function testHexPlaceholderPassesThrough(): void
    {
        $hex = '<' . str_repeat('0', 32768) . '>';
        self::assertSame($hex, RawValue::of($hex)->toBytes());
    }
}
