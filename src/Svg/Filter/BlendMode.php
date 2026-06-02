<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

/** @internal */
enum BlendMode: string
{
    case NORMAL = 'normal';
    case MULTIPLY = 'multiply';
    case SCREEN = 'screen';
    case DARKEN = 'darken';
    case LIGHTEN = 'lighten';

    public static function fromString(string $value, self $default = self::NORMAL): self
    {
        return self::tryFrom(trim($value)) ?? $default;
    }
}
