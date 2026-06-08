<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Font;

/**
 * A contiguous span of text sharing a single visual style.
 *
 * Produced by the inline parser; consumed by the LineBreaker, which slices
 * runs into PositionedSegments as it lays them into lines. Font sizes are in
 * POINTS (typographic convention). $isCode flags inline code spans; $url is
 * the link target when the run is part of a hyperlink, otherwise null.
 *
 * $linkGroup is the per-occurrence link id (a distinct integer per LinkSpan,
 * shared by every run inside that span); it is null outside any link.
 *
 * @internal
 */
final readonly class StyledRun
{
    public function __construct(
        public string $text,
        public Font $font,
        public Color $color,
        public float $sizePt,
        public bool $isCode,
        public ?string $url,
        public ?int $linkGroup = null,
    ) {}
}
