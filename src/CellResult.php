<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

/**
 * Readonly result of `Page::cell()`. Carries the bottom-right anchor of the
 * cell (`x`, `y`) for stacking, the resolved height, and metadata about the
 * wrap pipeline.
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
    ) {}
}
