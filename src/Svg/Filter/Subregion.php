<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

/**
 * Optional per-primitive subregion (x, y, width, height).
 * Null fields mean "use the filter region default".
 *
 * @internal
 */
final readonly class Subregion
{
    public function __construct(
        public ?float $x,
        public ?float $y,
        public ?float $width,
        public ?float $height,
    ) {}
}
