<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * A raster <image> placed in the SVG. Carries its viewport (x, y, width,
 * height) in local user units, the preserveAspectRatio fit policy, the resolved
 * group opacity, the intrinsic pixel size of the decoded raster, and an index
 * into SvgMetadata::$embeddedImages identifying which raster to draw. Has no
 * fill/stroke, so it implements SvgNode directly (not SvgShape).
 *
 * @internal
 */
final readonly class SvgImage implements SvgNode
{
    public function __construct(
        public ?SvgMatrix $transform,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public PreserveAspectRatio $aspectRatio,
        public float $opacity,
        public int $imageIndex,
        public int $intrinsicWidth,
        public int $intrinsicHeight,
    ) {}
}
