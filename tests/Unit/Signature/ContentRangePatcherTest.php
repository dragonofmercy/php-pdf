<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\ContentRangePatcher;
use DragonOfMercy\PhpPdf\Signature\SignatureDictionaryEmitter;
use PHPUnit\Framework\TestCase;

final class ContentRangePatcherTest extends TestCase
{
    private function buffer(int $maxBytes): string
    {
        $contents = '<' . str_repeat('0', $maxBytes * 2) . '>';
        return "%PDF-1.7\nprefix\n/ByteRange " . SignatureDictionaryEmitter::BYTERANGE_PLACEHOLDER
            . " /Contents " . $contents . "\nsuffix\n%%EOF\n";
    }

    public function testPatchesByteRangeAndContents(): void
    {
        $captured = null;
        $buf = $this->buffer(64);
        $out = (new ContentRangePatcher())->patch($buf, 0, 64 * 2, function (string $data) use (&$captured): string {
            $captured = $data;
            return "\xAA\xBB";
        });

        self::assertSame(strlen($buf), strlen($out));
        self::assertStringNotContainsString('[0 0000000000 0000000000 0000000000]', $out);
        if (preg_match('~/ByteRange \[0 (\d{10}) (\d{10}) (\d{10})\]~', $out, $m) !== 1) {
            self::fail('ByteRange not patched');
        }
        $lt = (int) $m[1];
        $afterGap = (int) $m[2];
        self::assertSame(strlen($out) - $afterGap, (int) $m[3]);
        self::assertSame('<', $out[$lt]);
        self::assertSame('>', $out[$afterGap - 1]);
        $hex = substr($out, $lt + 1, ($afterGap - 1) - ($lt + 1));
        self::assertStringStartsWith('AABB', $hex);
        self::assertSame(substr($out, 0, $lt) . substr($out, $afterGap), $captured);
    }

    public function testSearchFromSkipsEarlierContents(): void
    {
        $first = $this->buffer(64);
        $second = $this->buffer(64);
        $buf = $first . $second;
        $out = (new ContentRangePatcher())->patch($buf, strlen($first), 64 * 2, fn (string $d): string => "\x01");
        // The first placeholder is untouched: its all-zero /Contents is still present.
        self::assertStringContainsString('/Contents <' . str_repeat('0', 128) . '>', $out);
        // The second placeholder was patched (its hex now starts with 01).
        self::assertSame(2, substr_count($out, '/ByteRange'));
    }

    public function testThrowsWhenTokenTooLarge(): void
    {
        $this->expectException(PdfException::class);
        (new ContentRangePatcher())->patch($this->buffer(2), 0, 2 * 2, fn (string $d): string => str_repeat("\xFF", 10));
    }
}
