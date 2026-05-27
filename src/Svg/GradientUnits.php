<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/** @internal */
enum GradientUnits: string
{
    case OBJECT_BOUNDING_BOX = 'objectBoundingBox';
    case USER_SPACE_ON_USE = 'userSpaceOnUse';
}
