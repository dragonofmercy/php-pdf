<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Render-time record of a soft-mask usage. ImageEmbedder allocates one child
 * indirect Form XObject per entry, builds the Form's /Group << /S /Transparency
 * /CS /DeviceRGB >> wrapper, attaches /Resources, and references it from the
 * outer ExtGState's /SMask /G.
 *
 * The luminance->alpha conversion is handled by the PDF reader because the
 * containing ExtGState carries /SMask /S /Luminosity, not by us.
 *
 * @internal
 */
final readonly class EmbeddedMask
{
    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox mask bounds [llx lly urx ury]
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix mask -> user matrix [a b c d e f]
     * @param array<string, array{ca: float, CA: float, smaskEmbeddedIndex: ?int}> $extGStates inner ExtGState entries for the mask's /Resources
     */
    public function __construct(
        public array  $bbox,
        public array  $matrix,
        public array  $extGStates,
        public string $contentBytes,
    ) {}
}
