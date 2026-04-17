<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Writer\Object;

use PhpPdf\Writer\Object\HexString;
use PHPUnit\Framework\TestCase;

final class HexStringTest extends TestCase
{
    public function testWrapsHexInAngleBrackets(): void
    {
        self::assertSame('<DEADBEEF>', HexString::of('DEADBEEF')->toBytes());
    }

    public function testLowercaseHexIsUppercased(): void
    {
        self::assertSame('<ABCDEF>', HexString::of('abcdef')->toBytes());
    }

    public function testEmptyStringProducesEmptyHex(): void
    {
        self::assertSame('<>', HexString::of('')->toBytes());
    }
}
