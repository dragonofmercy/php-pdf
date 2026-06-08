<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Text\Bidi;

/**
 * Maps a Unicode codepoint to its bidirectional character class (the property
 * Bidi_Class), using the generated range table {@see BidiClassData}. Any
 * codepoint outside every listed range defaults to 'L'.
 *
 * @internal
 */
final class BidiCharacterType
{
    /**
     * The Unicode bidi class code: one of L, R, AL, EN, ES, ET, AN, CS, NSM,
     * BN, B, S, WS, ON (plus the explicit-format classes we never emit).
     */
    public static function of(int $codepoint): string
    {
        $ranges = BidiClassData::RANGES;
        $lo = 0;
        $hi = count($ranges) - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $range = $ranges[$mid];
            if ($codepoint < $range[0]) {
                $hi = $mid - 1;
            } elseif ($codepoint > $range[1]) {
                $lo = $mid + 1;
            } else {
                return $range[2];
            }
        }
        return 'L';
    }
}
