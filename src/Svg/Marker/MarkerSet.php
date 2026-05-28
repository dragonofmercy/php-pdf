<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Marker;

/**
 * Immutable triple of marker references for the start, mid, and end positions
 * of a stroked geometry. null in any slot means no marker at that position.
 *
 * @internal
 */
final readonly class MarkerSet
{
    public function __construct(
        public ?SvgMarker $start = null,
        public ?SvgMarker $mid = null,
        public ?SvgMarker $end = null,
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    public function withStart(?SvgMarker $m): self
    {
        return new self($m, $this->mid, $this->end);
    }

    public function withMid(?SvgMarker $m): self
    {
        return new self($this->start, $m, $this->end);
    }

    public function withEnd(?SvgMarker $m): self
    {
        return new self($this->start, $this->mid, $m);
    }
}
