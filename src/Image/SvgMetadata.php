<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\ViewBox;

final readonly class SvgMetadata
{
    public function __construct(
        public ViewBox $viewBox,
        public PreserveAspectRatio $aspectRatio,
        public SvgGroup $root,
    ) {}

    public function intrinsicWidth(): float
    {
        return $this->viewBox->width;
    }

    public function intrinsicHeight(): float
    {
        return $this->viewBox->height;
    }
}
