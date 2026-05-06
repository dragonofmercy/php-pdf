<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

/**
 * Strategy for fitting cell text into the available width.
 *
 * - NONE: word-wrap and force-break long words (default).
 * - CONDENSE: horizontal text scaling via Tz operator, no wrap.
 * - SHRINK: reduce font size proportionally so the longest line fits, no wrap.
 */
enum Fit
{
    case NONE;
    case CONDENSE;
    case SHRINK;
}
