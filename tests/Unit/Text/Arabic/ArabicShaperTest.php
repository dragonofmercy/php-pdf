<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Text\Arabic;

use DragonOfMercy\PhpPdf\Text\Arabic\ArabicShaper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArabicShaperTest extends TestCase
{
    /** Build a UTF-8 string from codepoints. */
    private static function u(int ...$cps): string
    {
        $s = '';
        foreach ($cps as $cp) {
            $s .= mb_chr($cp, 'UTF-8');
        }
        return $s;
    }

    public function testIsolatedSingleLetter(): void
    {
        // lone beh -> isolated FE8F
        self::assertSame(self::u(0xFE8F), ArabicShaper::shape(self::u(0x0628)));
    }

    public function testDualJoiningWordNoonMeemReh(): void
    {
        // noon meem reh -> initial FEE7, medial FEE4, final FEAE
        self::assertSame(
            self::u(0xFEE7, 0xFEE4, 0xFEAE),
            ArabicShaper::shape(self::u(0x0646, 0x0645, 0x0631)),
        );
    }

    public function testTransparentMarkDoesNotBreakJoin(): void
    {
        // beh + fatha + beh -> initial FE91, fatha 064E preserved, final FE90
        self::assertSame(
            self::u(0xFE91, 0x064E, 0xFE90),
            ArabicShaper::shape(self::u(0x0628, 0x064E, 0x0628)),
        );
    }

    #[DataProvider('byteIdentityProvider')]
    public function testNonArabicReturnedUnchanged(string $input): void
    {
        self::assertSame($input, ArabicShaper::shape($input));
    }

    /** @return iterable<string, array{string}> */
    public static function byteIdentityProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'ascii' => ['Hello, World 2026!'];
        yield 'hebrew' => ["\u{05E9}\u{05DC}\u{05D5}\u{05DD}"];
        yield 'latin accents' => ['cafe resume'];
    }

    public function testMixedArabicAndLatinShapesOnlyArabic(): void
    {
        // "ab" + noon meem reh -> "ab" + FEE7 FEE4 FEAE
        self::assertSame(
            'ab' . self::u(0xFEE7, 0xFEE4, 0xFEAE),
            ArabicShaper::shape('ab' . self::u(0x0646, 0x0645, 0x0631)),
        );
    }

    public function testLamAlefIsolatedLigature(): void
    {
        // lam + alef (start of run) -> isolated ligature FEFB (one codepoint)
        self::assertSame(self::u(0xFEFB), ArabicShaper::shape(self::u(0x0644, 0x0627)));
    }

    public function testLamAlefFinalLigatureAfterJoiner(): void
    {
        // beh + lam + alef -> initial beh FE91, final lam-alef FEFC
        self::assertSame(
            self::u(0xFE91, 0xFEFC),
            ArabicShaper::shape(self::u(0x0628, 0x0644, 0x0627)),
        );
    }

    #[DataProvider('lamAlefVariantProvider')]
    public function testLamAlefVariants(int $alef, int $isoLigature): void
    {
        self::assertSame(self::u($isoLigature), ArabicShaper::shape(self::u(0x0644, $alef)));
    }

    /** @return iterable<string, array{int, int}> */
    public static function lamAlefVariantProvider(): iterable
    {
        yield 'plain 0627'       => [0x0627, 0xFEFB];
        yield 'madda 0622'       => [0x0622, 0xFEF5];
        yield 'hamza above 0623' => [0x0623, 0xFEF7];
        yield 'hamza below 0625' => [0x0625, 0xFEF9];
    }
}
