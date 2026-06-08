<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Text\Bidi;

use DragonOfMercy\PhpPdf\Text\Bidi\BidiAlgorithm;
use PHPUnit\Framework\TestCase;

final class BidiAlgorithmTest extends TestCase
{
    /** @return list<int> */
    private static function cp(string $s): array
    {
        $out = [];
        $len = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ord = mb_ord(mb_substr($s, $i, 1, 'UTF-8'), 'UTF-8');
            $out[] = $ord;
        }
        return $out;
    }

    /** @param list<int> $cps */
    private static function str(array $cps): string
    {
        $s = '';
        foreach ($cps as $cp) {
            $s .= mb_chr($cp, 'UTF-8');
        }
        return $s;
    }

    public function testPureLtrIsIdentity(): void
    {
        $cps = self::cp('abc');
        $levels = BidiAlgorithm::resolveLevels($cps, 0);
        $visual = BidiAlgorithm::reorderLine($cps, $levels, 0);
        self::assertSame('abc', self::str($visual));
    }

    public function testPureHebrewRtlReverses(): void
    {
        $cps = self::cp("\u{05D0}\u{05D1}\u{05D2}");
        $levels = BidiAlgorithm::resolveLevels($cps, 1);
        $visual = BidiAlgorithm::reorderLine($cps, $levels, 1);
        self::assertSame("\u{05D2}\u{05D1}\u{05D0}", self::str($visual));
    }

    public function testLatinRunInsideRtlKeepsLatinOrder(): void
    {
        $cps = self::cp("\u{05D0}\u{05D1} ab");
        $levels = BidiAlgorithm::resolveLevels($cps, 1);
        $visual = BidiAlgorithm::reorderLine($cps, $levels, 1);
        self::assertSame("ab \u{05D1}\u{05D0}", self::str($visual));
    }

    public function testNumberAfterHebrewStaysLtr(): void
    {
        $cps = self::cp("\u{05D0}\u{05D1}12");
        $levels = BidiAlgorithm::resolveLevels($cps, 1);
        $visual = BidiAlgorithm::reorderLine($cps, $levels, 1);
        self::assertSame("12\u{05D1}\u{05D0}", self::str($visual));
    }

    public function testBracketMirroredAtOddLevel(): void
    {
        $cps = self::cp("(\u{05D0})");
        $levels = BidiAlgorithm::resolveLevels($cps, 1);
        $visual = BidiAlgorithm::reorderLine($cps, $levels, 1);
        self::assertSame("(\u{05D0})", self::str($visual));
    }
}
