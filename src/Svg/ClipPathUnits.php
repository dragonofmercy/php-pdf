<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * The coordinate system a <clipPath> uses for its child geometry.
 *
 * @internal
 */
enum ClipPathUnits: string
{
    case USER_SPACE_ON_USE = 'userSpaceOnUse';
    case OBJECT_BOUNDING_BOX = 'objectBoundingBox';
}
