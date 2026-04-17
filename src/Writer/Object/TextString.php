<?php

declare(strict_types=1);

namespace PhpPdf\Writer\Object;

/**
 * PDF text string (PDF 1.7 §7.9.2.2). UTF-8 input is re-encoded as UTF-16BE
 * with a leading BOM (FEFF), then serialized as an uppercase hex string.
 *
 * @internal
 */
final readonly class TextString implements PdfObject
{
    private function __construct(private string $utf8) {}

    public static function of(string $utf8): self
    {
        return new self($utf8);
    }

    public function toBytes(): string
    {
        $utf16 = mb_convert_encoding($this->utf8, 'UTF-16BE', 'UTF-8');
        return '<' . strtoupper(bin2hex("\xFE\xFF" . $utf16)) . '>';
    }
}
