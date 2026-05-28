<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * SVG `patternUnits` attribute on <pattern>. Per spec the default is
 * objectBoundingBox: tile bounds (x, y, width, height) are interpreted in
 * [0,1] units of the painted shape's bounding box. userSpaceOnUse takes
 * them as user-space coordinates.
 *
 * @internal
 */
enum PatternUnits: string
{
    case USER_SPACE_ON_USE = 'userSpaceOnUse';
    case OBJECT_BOUNDING_BOX = 'objectBoundingBox';

    public static function tryFromName(?string $raw): self
    {
        if ($raw === null) {
            return self::OBJECT_BOUNDING_BOX;
        }
        return self::tryFrom($raw) ?? self::OBJECT_BOUNDING_BOX;
    }
}
