<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

enum MeetOrSlice: string
{
    /** Scale uniformly to fit entirely; may leave letterboxing. */
    case MEET = 'meet';

    /** Scale uniformly to cover entirely; may crop content. */
    case SLICE = 'slice';
}
