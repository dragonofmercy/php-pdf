<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Text\Bidi\BidiAlgorithm;
use DragonOfMercy\PhpPdf\Text\Bidi\BidiCharacterType;
use DragonOfMercy\PhpPdf\Text\Direction;

/**
 * Reorders one laid-out Markdown Line from logical to visual order using the
 * Unicode bidi algorithm, carrying each codepoint's style provenance so the
 * result is re-segmented by style (a styled run split by the reorder becomes
 * several visual segments, e.g. multiple link rects). Widths are re-measured
 * via the injected measure closure. A non-RTL base with no RTL codepoint
 * returns the input Line unchanged (byte-identity).
 *
 * @internal
 */
final class LineReorderer
{
    /**
     * @param callable(string, Font, float): float $measure
     */
    public static function reorder(Line $line, Direction $base, callable $measure): Line
    {
        if ($line->segments === []) {
            return $line;
        }

        /** @var list<int> $cps */
        $cps = [];
        /** @var list<int> $owner */
        $owner = [];
        foreach ($line->segments as $segIndex => $segment) {
            foreach (mb_str_split($segment->run->text, 1, 'UTF-8') as $ch) {
                $cps[] = mb_ord($ch, 'UTF-8');
                $owner[] = $segIndex;
            }
        }

        if ($cps === []) {
            return $line;
        }

        if ($base !== Direction::RTL && !self::hasRtl($cps)) {
            return $line;
        }

        $paragraphLevel = $base === Direction::RTL ? 1 : 0;
        $levels = BidiAlgorithm::resolveLevels($cps, $paragraphLevel);

        // One pass yields both the visual->logical index permutation (for style
        // provenance) and the L4-mirrored visual codepoints (to draw).
        [$order, $visual] = BidiAlgorithm::reorderForVisual($cps, $levels, $paragraphLevel);

        /** @var list<PositionedSegment> $segments */
        $segments = [];
        $xOffset = 0.0;
        $v = 0;
        $count = count($order);
        while ($v < $count) {
            $segIndex = $owner[$order[$v]];
            $sourceRun = $line->segments[$segIndex]->run;
            $text = '';
            while ($v < $count && $owner[$order[$v]] === $segIndex) {
                $text .= mb_chr($visual[$v], 'UTF-8');
                $v++;
            }
            $width = $measure($text, $sourceRun->font, $sourceRun->sizePt);
            $newRun = new StyledRun($text, $sourceRun->font, $sourceRun->color, $sourceRun->sizePt, $sourceRun->isCode, $sourceRun->url, $sourceRun->linkGroup);
            $segments[] = new PositionedSegment($newRun, $xOffset, $width);
            $xOffset += $width;
        }

        return new Line($segments, $line->heightPt);
    }

    /** @param list<int> $cps */
    private static function hasRtl(array $cps): bool
    {
        foreach ($cps as $cp) {
            $class = BidiCharacterType::of($cp);
            if ($class === 'R' || $class === 'AL' || $class === 'AN') {
                return true;
            }
        }

        return false;
    }
}
