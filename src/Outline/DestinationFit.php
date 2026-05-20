<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Outline;

/**
 * PDF destination fit variant (PDF 1.7 section 12.3.2.2). Three variants are
 * supported; others (`/FitV`, `/FitR`, `/FitB`, `/FitBH`, `/FitBV`) are
 * deferred. Pair with {@see Destination} via its named constructors.
 */
enum DestinationFit
{
    /** `/XYZ left top zoom` - explicit top-left + optional zoom (null = "keep current"). */
    case Xyz;

    /** `/Fit` - whole page fits the viewport. */
    case Fit;

    /** `/FitH top` - page width fills the viewport, scrolled to a given top. */
    case FitH;
}
