<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\Custom\Utf8ToCidEncoder;
use PHPUnit\Framework\TestCase;

final class Utf8ToCidEncoderTest extends TestCase
{
    private function makeFont(): ParsedTtf
    {
        return new ParsedTtf(
            bytes: '',
            postScriptName: 'Test',
            unitsPerEm: 1000,
            ascent: 800,
            descent: -200,
            capHeight: 700,
            xHeight: 500,
            bbox: [0, 0, 1000, 1000],
            italicAngle: 0,
            weight: 400,
            flags: 32,
            cmap: [
                0x41 => 36,
                0x42 => 37,
                0xE9 => 233,
                0x1F600 => 9000,
            ],
            advanceWidthsByGid: [],
        );
    }

    public function testEncodesAsciiToTwoByteGids(): void
    {
        $bytes = Utf8ToCidEncoder::encode('AB', $this->makeFont());
        self::assertSame("\x00\x24\x00\x25", $bytes);
    }

    public function testEncodesAccentedLatinViaCmap(): void
    {
        $bytes = Utf8ToCidEncoder::encode("\u{00E9}", $this->makeFont());
        self::assertSame("\x00\xE9", $bytes);
    }

    public function testCodepointAbsentFromCmapFallsBackToGid0(): void
    {
        $bytes = Utf8ToCidEncoder::encode("\u{2603}", $this->makeFont());
        self::assertSame("\x00\x00", $bytes);
    }

    public function testEmptyStringEncodesToEmpty(): void
    {
        self::assertSame('', Utf8ToCidEncoder::encode('', $this->makeFont()));
    }

    public function testEncodesNonBmpCodepoint(): void
    {
        $bytes = Utf8ToCidEncoder::encode("\u{1F600}", $this->makeFont());
        self::assertSame("\x23\x28", $bytes);
    }

    public function testMixesAsciiAccentsAndUnknown(): void
    {
        $bytes = Utf8ToCidEncoder::encode("A\u{2603}\u{00E9}", $this->makeFont());
        self::assertSame("\x00\x24\x00\x00\x00\xE9", $bytes);
    }
}
