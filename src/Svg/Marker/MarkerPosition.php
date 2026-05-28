<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Marker;

/** @internal */
final readonly class MarkerPosition
{
    public function __construct(
        public float $x,
        public float $y,
        public float $angleDeg,
        public MarkerKind $kind,
    ) {}
}
