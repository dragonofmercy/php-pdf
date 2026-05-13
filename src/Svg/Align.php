<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * The 9 alignment modes of preserveAspectRatio. `NONE` disables aspect ratio
 * preservation entirely (the viewBox is stretched to fill the viewport).
 */
enum Align: string
{
    case NONE        = 'none';
    case X_MIN_Y_MIN = 'xMinYMin';
    case X_MID_Y_MIN = 'xMidYMin';
    case X_MAX_Y_MIN = 'xMaxYMin';
    case X_MIN_Y_MID = 'xMinYMid';
    case X_MID_Y_MID = 'xMidYMid';
    case X_MAX_Y_MID = 'xMaxYMid';
    case X_MIN_Y_MAX = 'xMinYMax';
    case X_MID_Y_MAX = 'xMidYMax';
    case X_MAX_Y_MAX = 'xMaxYMax';
}
