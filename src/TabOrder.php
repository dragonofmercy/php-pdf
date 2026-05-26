<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

/**
 * Tab order for a page's form fields (PDF 32000-1 /Tabs): ROW (/R), COLUMN
 * (/C), or STRUCTURE (/S). Null on a page means no /Tabs (reader default).
 */
enum TabOrder
{
    case ROW;
    case COLUMN;
    case STRUCTURE;
}
