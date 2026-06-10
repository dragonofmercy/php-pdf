<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

/**
 * Where an object lives according to the cross-reference data
 * (PDF 1.7 7.5.4 / 7.5.8.3).
 *
 * @internal
 */
enum XrefEntryKind
{
    case Free;
    case InFile;
    case InObjectStream;
}
