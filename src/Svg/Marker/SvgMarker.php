<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Marker;

use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use DragonOfMercy\PhpPdf\Svg\SvgNode;
use DragonOfMercy\PhpPdf\Svg\ViewBox;

/**
 * A parsed <marker> definition referenceable via marker-start / marker-mid /
 * marker-end / marker (shorthand). Children pre-parsed via the Parser's
 * parseChildrenAsMarker (text/image stripped, nested url() refs scrubbed).
 *
 * @internal
 */
final readonly class SvgMarker
{
    /** @param list<SvgNode> $nodes */
    public function __construct(
        public ?ViewBox $viewBox,
        public PreserveAspectRatio $aspectRatio,
        public float $markerWidth,
        public float $markerHeight,
        public float $refX,
        public float $refY,
        public MarkerUnits $units,
        public MarkerOrient $orient,
        public array $nodes,
    ) {}
}
