<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

final readonly class SvgPolygon implements SvgShape
{
    /**
     * @param list<array{0: float, 1: float}> $points
     */
    public function __construct(
        private ?SvgMatrix $transform,
        private SvgPaint $paint,
        public array $points,
    ) {}

    public function transform(): ?SvgMatrix { return $this->transform; }
    public function paint(): SvgPaint { return $this->paint; }
}
