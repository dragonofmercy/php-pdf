<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

/** @internal */
enum ColorMatrixType: string
{
    case MATRIX = 'matrix';
    case SATURATE = 'saturate';
    case HUE_ROTATE = 'hueRotate';
    case LUMINANCE_TO_ALPHA = 'luminanceToAlpha';

    public static function fromString(string $value, self $default = self::MATRIX): self
    {
        return self::tryFrom(trim($value)) ?? $default;
    }
}
