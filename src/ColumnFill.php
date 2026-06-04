<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

/**
 * Fill strategy for a multi-column block. SEQUENTIAL fills each column to the
 * bottom before the next; BALANCED (not yet implemented) equalizes column heights.
 */
enum ColumnFill
{
    case SEQUENTIAL;
    case BALANCED;
}
