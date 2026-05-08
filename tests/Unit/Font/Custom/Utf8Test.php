<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Font\Custom\Utf8;
use PHPUnit\Framework\TestCase;

final class Utf8Test extends TestCase
{
    /** @return list<array{0: int, 1: int}> */
    private function collect(string $utf8): array
    {
        return iterator_to_array(Utf8::codepoints($utf8), false);
    }

    public function testEmptyStringYieldsNothing(): void
    {
        self::assertSame([], $this->collect(''));
    }

    public function testAsciiYieldsOneBytePairs(): void
    {
        self::assertSame([[65, 1], [66, 1], [67, 1]], $this->collect('ABC'));
    }

    public function testTwoByteSequence(): void
    {
        // U+00E9 (e acute) -> 0xC3 0xA9
        self::assertSame([[0xE9, 2]], $this->collect("\xC3\xA9"));
    }

    public function testThreeByteSequence(): void
    {
        // U+20AC (EUR) -> 0xE2 0x82 0xAC
        self::assertSame([[0x20AC, 3]], $this->collect("\xE2\x82\xAC"));
    }

    public function testFourByteSequence(): void
    {
        // U+1F600 (grinning face) -> 0xF0 0x9F 0x98 0x80
        self::assertSame([[0x1F600, 4]], $this->collect("\xF0\x9F\x98\x80"));
    }

    public function testInvalidLeadingByteYieldsMinusOne(): void
    {
        // 0xFF is not a valid UTF-8 leading byte
        self::assertSame([[-1, 1]], $this->collect("\xFF"));
    }

    public function testTruncatedTwoByteSequenceYieldsMinusOne(): void
    {
        // 0xC3 alone (without continuation byte) is invalid
        self::assertSame([[-1, 1]], $this->collect("\xC3"));
    }

    public function testMixedSequence(): void
    {
        // "Aé" + EUR + grinning face
        $bytes = "A\xC3\xA9\xE2\x82\xAC\xF0\x9F\x98\x80";
        self::assertSame(
            [[65, 1], [0xE9, 2], [0x20AC, 3], [0x1F600, 4]],
            $this->collect($bytes),
        );
    }
}
