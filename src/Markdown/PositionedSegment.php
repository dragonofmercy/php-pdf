<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown;

/**
 * A run (possibly trimmed) placed at a measured horizontal offset on a Line.
 *
 * Consecutive tokens sharing the same source StyledRun instance are merged
 * into one segment by the LineBreaker, so a font change on a line starts a new
 * segment. $run->text holds the text actually placed here; $xOffsetPt is the
 * segment's left edge measured from the line start; $widthPt its measured
 * width. All values are in POINTS.
 *
 * @internal
 */
final readonly class PositionedSegment
{
    public function __construct(
        public StyledRun $run,
        public float $xOffsetPt,
        public float $widthPt,
    ) {}
}
