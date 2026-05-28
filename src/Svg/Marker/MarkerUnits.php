<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Marker;

/** @internal */
enum MarkerUnits: string
{
    case STROKE_WIDTH = 'strokeWidth';
    case USER_SPACE_ON_USE = 'userSpaceOnUse';

    public static function tryFromName(?string $raw): self
    {
        if ($raw === null) {
            return self::STROKE_WIDTH;
        }
        return self::tryFrom($raw) ?? self::STROKE_WIDTH;
    }
}
