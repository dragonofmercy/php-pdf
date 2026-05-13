<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

final readonly class SvgRect implements SvgShape
{
    public function __construct(
        private ?SvgMatrix $transform,
        private SvgPaint $paint,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public float $rx,
        public float $ry,
    ) {}

    public function transform(): ?SvgMatrix { return $this->transform; }
    public function paint(): SvgPaint { return $this->paint; }

    public function hasRoundedCorners(): bool
    {
        return $this->rx > 0.0 || $this->ry > 0.0;
    }
}
