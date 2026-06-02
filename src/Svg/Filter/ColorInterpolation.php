<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

/** @internal */
enum ColorInterpolation: string
{
    case LINEAR_RGB = 'linearRGB';
    case SRGB = 'sRGB';

    public static function fromString(string $value, self $default = self::LINEAR_RGB): self
    {
        return self::tryFrom(trim($value)) ?? $default;
    }
}
