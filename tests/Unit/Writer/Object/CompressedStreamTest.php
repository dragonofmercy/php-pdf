<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer\Object;

use DragonOfMercy\PhpPdf\Writer\Object\CompressedStream;
use PHPUnit\Framework\TestCase;

final class CompressedStreamTest extends TestCase
{
    public function testFlateDecodeFilterIsInDict(): void
    {
        $bytes = CompressedStream::of('content')->toBytes();
        self::assertStringContainsString('/Filter /FlateDecode', $bytes);
    }

    public function testLengthMatchesCompressedByteCount(): void
    {
        $bytes = CompressedStream::of('hello')->toBytes();

        if (!preg_match('/<< \/Length (\d+) /', $bytes, $m)) {
            self::fail('Regex should match');
        }
        $declared = (int) $m[1];

        $streamStart = strpos($bytes, "stream\n");
        $endstreamPos = strrpos($bytes, "\nendstream");
        self::assertIsInt($streamStart);
        self::assertIsInt($endstreamPos);
        $actual = substr($bytes, $streamStart + strlen("stream\n"), $endstreamPos - $streamStart - strlen("stream\n"));

        self::assertSame($declared, strlen($actual));
    }

    public function testRoundTripReturnsOriginalContent(): void
    {
        $original = 'Hello, World! ' . str_repeat('x', 200);
        $bytes = CompressedStream::of($original)->toBytes();

        $streamStart = strpos($bytes, "stream\n");
        $endstreamPos = strrpos($bytes, "\nendstream");
        self::assertIsInt($streamStart);
        self::assertIsInt($endstreamPos);
        $compressed = substr($bytes, $streamStart + strlen("stream\n"), $endstreamPos - $streamStart - strlen("stream\n"));

        self::assertSame($original, gzuncompress($compressed));
    }

    public function testCompressedContentStartsWithZlibMagicByte(): void
    {
        $bytes = CompressedStream::of('Hello, World!')->toBytes();

        $streamStart = strpos($bytes, "stream\n");
        $endstreamPos = strrpos($bytes, "\nendstream");
        self::assertIsInt($streamStart);
        self::assertIsInt($endstreamPos);
        $compressed = substr($bytes, $streamStart + strlen("stream\n"), $endstreamPos - $streamStart - strlen("stream\n"));

        self::assertSame(0x78, ord($compressed[0]), 'zlib stream should start with magic byte 0x78');
    }
}
