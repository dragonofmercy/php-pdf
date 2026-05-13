<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

final readonly class SvgLine implements SvgShape
{
    public function __construct(
        private ?SvgMatrix $transform,
        private SvgPaint $paint,
        public float $x1,
        public float $y1,
        public float $x2,
        public float $y2,
    ) {}

    public function transform(): ?SvgMatrix { return $this->transform; }
    public function paint(): SvgPaint { return $this->paint; }
}
