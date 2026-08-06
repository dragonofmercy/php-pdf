<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Text\Bidi;

use DragonOfMercy\PhpPdf\Text\RangeTable;

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
        return RangeTable::lookup(BidiClassData::RANGES, $codepoint, 'L');
    }
}
