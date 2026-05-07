<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

/**
 * How the PDF viewer arranges pages on opening (PDF 1.7 §7.7.2, /PageLayout).
 *
 * Single* layouts: viewer paginates one page (or pair) at a time, no scroll
 * across pages. *Column layouts: continuous scroll. *Right variants put the
 * odd-numbered page on the right (book/magazine reading order with a lone
 * cover); *Left variants put the odd page on the left.
 *
 * Defaults to SinglePage when not set on the catalog.
 */
enum PageLayout: string
{
    case SINGLE_PAGE       = 'SinglePage';
    case ONE_COLUMN        = 'OneColumn';
    case TWO_COLUMN_LEFT   = 'TwoColumnLeft';
    case TWO_COLUMN_RIGHT  = 'TwoColumnRight';
    case TWO_PAGE_LEFT     = 'TwoPageLeft';
    case TWO_PAGE_RIGHT    = 'TwoPageRight';
}
