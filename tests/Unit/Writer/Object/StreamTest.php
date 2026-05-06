<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer\Object;

use DragonOfMercy\PhpPdf\Writer\Object\Stream;
use PHPUnit\Framework\TestCase;

final class StreamTest extends TestCase
{
    public function testEmptyContent(): void
    {
        $expected = "<< /Length 0 >>\nstream\n\nendstream";
        self::assertSame($expected, Stream::of('')->toBytes());
    }

    public function testNonEmptyContent(): void
    {
        $expected = "<< /Length 5 >>\nstream\nhello\nendstream";
        self::assertSame($expected, Stream::of('hello')->toBytes());
    }

    public function testLengthCountsBytesNotChars(): void
    {
        $content = "caf\xC3\xA9"; // 5 bytes, 4 chars
        $actual = Stream::of($content)->toBytes();
        self::assertStringStartsWith('<< /Length 5 >>', $actual);
    }
}
