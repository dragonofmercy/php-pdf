<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\ViewBox;

final readonly class SvgMetadata
{
    /**
     * @param list<\DragonOfMercy\PhpPdf\Image> $embeddedImages distinct rasters,
     *        deduped by contentHash; SvgImage::$imageIndex indexes this list.
     */
    public function __construct(
        public ViewBox $viewBox,
        public PreserveAspectRatio $aspectRatio,
        public SvgGroup $root,
        public array $embeddedImages = [],
    ) {}
}
