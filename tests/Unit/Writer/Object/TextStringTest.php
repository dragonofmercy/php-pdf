<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer\Object;

use DragonOfMercy\PhpPdf\Writer\Object\TextString;
use PHPUnit\Framework\TestCase;

final class TextStringTest extends TestCase
{
    public function testAsciiIsUtf16BeEncodedWithBom(): void
    {
        self::assertSame('<FEFF00410042>', TextString::of('AB')->toBytes());
    }

    public function testEmptyStringIsJustBom(): void
    {
        self::assertSame('<FEFF>', TextString::of('')->toBytes());
    }

    public function testAccentedCharactersAreEncoded(): void
    {
        self::assertSame('<FEFF00E9>', TextString::of('é')->toBytes());
    }

    public function testCjkCharactersAreEncoded(): void
    {
        self::assertSame('<FEFF4E2D>', TextString::of('中')->toBytes());
    }
}
