<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Exception;

/**
 * Thrown when input bytes cannot be parsed as PDF. Messages include the byte
 * offset and what was expected or found.
 */
class PdfParseException extends PdfException
{
}
