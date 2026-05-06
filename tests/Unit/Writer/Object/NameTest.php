<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer\Object;

use DragonOfMercy\PhpPdf\Writer\Object\Name;
use PHPUnit\Framework\TestCase;

final class NameTest extends TestCase
{
    public function testSimpleNameIsPrefixedWithSlash(): void
    {
        self::assertSame('/Type', Name::of('Type')->toBytes());
    }

    public function testSpaceIsHexEncoded(): void
    {
        self::assertSame('/Hello#20World', Name::of('Hello World')->toBytes());
    }

    public function testHashItselfIsEncoded(): void
    {
        self::assertSame('/Version#231', Name::of('Version#1')->toBytes());
    }

    public function testDelimitersAreEncoded(): void
    {
        self::assertSame('/A#28B#29', Name::of('A(B)')->toBytes());
    }

    public function testHighBytesAreHexEncoded(): void
    {
        self::assertSame('/caf#C3#A9', Name::of('caf' . "\xC3\xA9")->toBytes());
    }

    public function testEmptyNameIsJustSlash(): void
    {
        self::assertSame('/', Name::of('')->toBytes());
    }
}
