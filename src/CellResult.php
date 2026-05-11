<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

/**
 * Readonly result of `Page::cell()`. Carries the bottom-right anchor of the
 * cell (`x`, `y`) for stacking, the resolved width (useful when the cell was
 * auto-sized from text) and height, metadata about the wrap pipeline, and a
 * reference to the page on which the cell was actually emitted (which may
 * differ from the calling page when auto-page-break triggered a new page).
 */
final readonly class CellResult
{
    public function __construct(
        public float $x,
        public float $y,
        public float $height,
        public int $lineCount,
        public int $brokenWords,
        public bool $textOverflow,
        public float $effectiveWidth,
        public Page $page,
    ) {}
}
