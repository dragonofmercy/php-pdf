<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

/** @internal */
enum CompositeOperator: string
{
    case OVER = 'over';
    case IN = 'in';
    case OUT = 'out';
    case ATOP = 'atop';
    case XOR = 'xor';
    case ARITHMETIC = 'arithmetic';

    public static function fromString(string $value, self $default = self::OVER): self
    {
        return self::tryFrom(trim($value)) ?? $default;
    }
}
