<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Font;

use PhpPdf\Font\WinAnsiEncoder;
use PHPUnit\Framework\TestCase;

final class WinAnsiEncoderTest extends TestCase
{
    public function testAsciiPassThrough(): void
    {
        self::assertSame('Hello World', WinAnsiEncoder::encode('Hello World'));
    }

    public function testLatin1AccentsEncodeToTheirCodepoint(): void
    {
        // é = U+00E9 = 0xE9 in WinAnsi
        self::assertSame("\xE9", WinAnsiEncoder::encode('é'));
        // ü = U+00FC = 0xFC
        self::assertSame("\xFC", WinAnsiEncoder::encode('ü'));
        // ñ = U+00F1 = 0xF1
        self::assertSame("\xF1", WinAnsiEncoder::encode('ñ'));
    }

    public function testTypographicChars(): void
    {
        // € = U+20AC → 0x80
        self::assertSame("\x80", WinAnsiEncoder::encode('€'));
        // — (em dash) = U+2014 → 0x97
        self::assertSame("\x97", WinAnsiEncoder::encode('—'));
        // œ = U+0153 → 0x9C
        self::assertSame("\x9C", WinAnsiEncoder::encode('œ'));
    }

    public function testUnsupportedCharsReplacedByQuestionMark(): void
    {
        // 中 (CJK) has no WinAnsi mapping
        self::assertSame('?', WinAnsiEncoder::encode('中'));
        // α (Greek) has no WinAnsi mapping
        self::assertSame('?', WinAnsiEncoder::encode('α'));
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        self::assertSame('', WinAnsiEncoder::encode(''));
    }

    public function testMixedAsciiAndNonAscii(): void
    {
        self::assertSame("Pr\xE9s: 19,99\x80", WinAnsiEncoder::encode('Prés: 19,99€'));
    }

    public function testCafeResumeNaivete(): void
    {
        // "Résumé café naïveté" full test
        $expected = "R\xE9sum\xE9 caf\xE9 na\xEFvet\xE9";
        self::assertSame($expected, WinAnsiEncoder::encode('Résumé café naïveté'));
    }

    public function testMultiByteEmojiReplaced(): void
    {
        // 🎉 (U+1F389, requires UTF-16 surrogate pair) → ?
        self::assertSame('?', WinAnsiEncoder::encode('🎉'));
    }
}
