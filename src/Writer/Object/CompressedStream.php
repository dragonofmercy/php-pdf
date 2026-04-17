<?php

declare(strict_types=1);

namespace PhpPdf\Writer\Object;

use PhpPdf\Exception\PdfException;

/**
 * PDF stream with FlateDecode compression (PDF 1.7 §7.4.4).
 *
 * @internal
 */
final readonly class CompressedStream implements PdfObject
{
    private function __construct(private string $content) {}

    public static function of(string $content): self
    {
        return new self($content);
    }

    public function toBytes(): string
    {
        $compressed = gzcompress($this->content, 9);
        if ($compressed === false) {
            throw new PdfException('FlateDecode compression failed');
        }
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Length'), PdfNumber::ofInt(strlen($compressed)))
            ->withEntry(Name::of('Filter'), Name::of('FlateDecode'));
        return $dict->toBytes() . "\nstream\n" . $compressed . "\nendstream";
    }
}
