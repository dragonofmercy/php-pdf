<?php

declare(strict_types=1);

namespace PhpPdf\Writer\Object;

/**
 * PDF stream object (PDF 1.7 §7.3.8). Raw content, no compression.
 *
 * @internal
 */
final readonly class Stream implements PdfObject
{
    private function __construct(private string $content) {}

    public static function of(string $content): self
    {
        return new self($content);
    }

    public function toBytes(): string
    {
        $dict = Dictionary::empty()->withEntry(
            Name::of('Length'),
            PdfNumber::ofInt(strlen($this->content)),
        );
        return $dict->toBytes() . "\nstream\n" . $this->content . "\nendstream";
    }
}
