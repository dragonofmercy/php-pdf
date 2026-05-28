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
 * `patterns` carries inline shading-pattern dicts that the Form's content stream
 * references via /Pattern cs / Pn scn. Empty for opaque mask content (the <mask>
 * use case); populated for per-stop alpha gradient masks.
 *
 * @internal
 */
final readonly class EmbeddedMask
{
    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox mask bounds [llx lly urx ury]
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $matrix mask -> user matrix [a b c d e f]
     * @param array<string, array{ca: float, CA: float, smaskEmbeddedIndex: ?int}> $extGStates inner ExtGState entries for the mask's /Resources
     * @param array<string, string> $patterns inline shading-pattern dicts (name => dict) for the mask's /Resources/Pattern
     */
    public function __construct(
        public array  $bbox,
        public array  $matrix,
        public array  $extGStates,
        public array  $patterns,
        public string $contentBytes,
    ) {}
}
