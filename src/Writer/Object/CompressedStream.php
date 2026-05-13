<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Writer\Object;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * PDF stream with FlateDecode compression (PDF 1.7 §7.4.4).
 *
 * @internal
 */
final readonly class CompressedStream implements PdfObject
{
    private function __construct(private string $content, private ?Dictionary $extraDict = null) {}

    public static function of(string $content): self
    {
        return new self($content);
    }

    /**
     * Variant of {@see self::of()} that merges additional Dictionary entries
     * into the stream dict alongside /Length and /Filter. Used by Form XObjects
     * to declare /Type /Subtype /BBox /Resources etc.
     *
     * @internal
     */
    public static function ofWithDict(string $rawBytes, Dictionary $extra): self
    {
        return new self($rawBytes, $extra);
    }

    /**
     * Returns the gzcompressed bytes.
     *
     * @internal
     */
    public function compressedContent(): string
    {
        $compressed = gzcompress($this->content, 9);
        if ($compressed === false) {
            throw new \DragonOfMercy\PhpPdf\Exception\PdfException('FlateDecode compression failed');
        }
        return $compressed;
    }

    /**
     * @internal
     */
    public function filterDict(): Dictionary
    {
        return Dictionary::empty()->withEntry(Name::of('Filter'), Name::of('FlateDecode'));
    }

    public function toBytes(): string
    {
        $compressed = gzcompress($this->content, 9);
        if ($compressed === false) {
            throw new PdfException('FlateDecode compression failed');
        }
        $dict = $this->extraDict ?? Dictionary::empty();
        $dict = $dict
            ->withEntry(Name::of('Length'), PdfNumber::ofInt(strlen($compressed)))
            ->withEntry(Name::of('Filter'), Name::of('FlateDecode'));
        return $dict->toBytes() . "\nstream\n" . $compressed . "\nendstream";
    }
}
