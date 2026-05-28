<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Render-time record of a tiling pattern usage. ImageEmbedder allocates one
 * child indirect object per entry, builds the PatternType 1 stream dict from
 * these structured fields, and references it from /Resources/Pattern.
 *
 * @internal
 */
final readonly class EmbeddedPattern
{
    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox tile bounds [llx lly urx ury]
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix pattern->user matrix [a b c d e f]
     * @param array<string, array{ca: float, CA: float}> $extGStates inner ExtGState entries for the tile's /Resources
     */
    public function __construct(
        public array $bbox,
        public float $xStep,
        public float $yStep,
        public array $matrix,
        public array $extGStates,
        public string $contentBytes,
    ) {}
}
