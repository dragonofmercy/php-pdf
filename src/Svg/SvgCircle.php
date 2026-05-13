<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

final readonly class SvgCircle implements SvgShape
{
    public function __construct(
        private ?SvgMatrix $transform,
        private SvgPaint $paint,
        public float $cx,
        public float $cy,
        public float $r,
    ) {}

    public function transform(): ?SvgMatrix { return $this->transform; }
    public function paint(): SvgPaint { return $this->paint; }
}
