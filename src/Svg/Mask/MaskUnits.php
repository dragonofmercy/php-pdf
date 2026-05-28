<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Mask;

/**
 * SVG attributes `maskUnits` and `maskContentUnits` on <mask>.
 * Per spec, `maskUnits` defaults to objectBoundingBox while `maskContentUnits`
 * defaults to userSpaceOnUse. Two parsers to keep defaults explicit at call site.
 *
 * @internal
 */
enum MaskUnits: string
{
    case OBJECT_BOUNDING_BOX = 'objectBoundingBox';
    case USER_SPACE_ON_USE   = 'userSpaceOnUse';

    public static function tryFromName(?string $raw): self
    {
        if ($raw === null) {
            return self::OBJECT_BOUNDING_BOX;
        }
        return self::tryFrom($raw) ?? self::OBJECT_BOUNDING_BOX;
    }

    public static function tryFromContentName(?string $raw): self
    {
        if ($raw === null) {
            return self::USER_SPACE_ON_USE;
        }
        return self::tryFrom($raw) ?? self::USER_SPACE_ON_USE;
    }
}
