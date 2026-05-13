<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

/**
 * Container for parsed SVG content. Populated by {@see \DragonOfMercy\PhpPdf\Svg\Parser}.
 *
 * Stub for Task 1: only the constructor signature is locked in. ViewBox,
 * PreserveAspectRatio, and the root AST node are added in later tasks. Until
 * then this class is intentionally tiny so the Image::$metadata union compiles.
 */
final readonly class SvgMetadata
{
    public function __construct(
        public float $intrinsicWidth,
        public float $intrinsicHeight,
    ) {}
}
