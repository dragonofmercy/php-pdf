<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill;

/**
 * Escapes backslashes and parentheses for use in a PDF literal string operand.
 *
 * @internal
 */
final class PdfLiteralEscape
{
    public static function escape(string $bytes): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $bytes);
    }
}
