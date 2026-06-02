<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

/** @internal */
interface FilterPrimitive
{
    public ?string $result { get; }
    public ?Subregion $subregion { get; }
}
