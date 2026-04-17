<?php

declare(strict_types=1);

namespace PhpPdf\Writer\Object;

/**
 * PDF hexadecimal string (PDF 1.7 §7.3.4.3). Emitted as uppercase hex
 * between angle brackets, no whitespace.
 *
 * @internal
 */
final readonly class HexString implements PdfObject
{
    private function __construct(private string $hex) {}

    public static function of(string $hex): self
    {
        return new self(strtoupper($hex));
    }

    public function toBytes(): string
    {
        return '<' . $this->hex . '>';
    }
}
