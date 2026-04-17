<?php

declare(strict_types=1);

namespace PhpPdf\Writer\Object;

/**
 * PDF boolean object (PDF 1.7 §7.3.2). Emitted as the keyword `true` or `false`.
 *
 * @internal
 */
final readonly class PdfBoolean implements PdfObject
{
    private function __construct(private bool $value) {}

    public static function true(): self
    {
        return new self(true);
    }

    public static function false(): self
    {
        return new self(false);
    }

    public static function of(bool $value): self
    {
        return new self($value);
    }

    public function toBytes(): string
    {
        return $this->value ? 'true' : 'false';
    }
}
