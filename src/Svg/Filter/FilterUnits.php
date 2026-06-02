<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Svg\Filter;

/** @internal */
enum FilterUnits: string
{
    case OBJECT_BOUNDING_BOX = 'objectBoundingBox';
    case USER_SPACE_ON_USE = 'userSpaceOnUse';

    public static function fromString(string $value, self $default = self::OBJECT_BOUNDING_BOX): self
    {
        return self::tryFrom(trim($value)) ?? $default;
    }
}
