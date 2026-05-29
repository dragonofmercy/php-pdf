<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown;

/**
 * A single laid-out line of text: an ordered list of PositionedSegments plus
 * the line's height in POINTS (max segment size times a fixed 1.2 line-height
 * factor). Emitted by the LineBreaker.
 *
 * @internal
 */
final readonly class Line
{
    /**
     * @param list<PositionedSegment> $segments
     */
    public function __construct(
        public array $segments,
        public float $heightPt,
    ) {}

    /**
     * Right edge of the last segment (xOffset + width), or 0.0 if empty.
     */
    public function widthPt(): float
    {
        $last = $this->segments[count($this->segments) - 1] ?? null;

        if ($last === null) {
            return 0.0;
        }

        return $last->xOffsetPt + $last->widthPt;
    }
}
