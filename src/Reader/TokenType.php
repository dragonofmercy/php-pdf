<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

/**
 * Kinds of lexical tokens in PDF syntax (PDF 1.7 7.2).
 *
 * @internal
 */
enum TokenType
{
    case Integer;
    case Real;
    case Name;
    case LiteralString;
    case HexString;
    case DictOpen;
    case DictClose;
    case ArrayOpen;
    case ArrayClose;
    case Keyword;
    case EndOfInput;
}
