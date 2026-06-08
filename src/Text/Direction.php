<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Text;

/**
 * Text writing direction. LTR / RTL force a paragraph base direction; AUTO
 * derives it from the first strong character (Unicode bidi rules P2/P3).
 */
enum Direction
{
    case LTR;
    case RTL;
    case AUTO;
}
