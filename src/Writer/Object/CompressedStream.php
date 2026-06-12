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

    /**
     * @param ?Dictionary $extra Additional dictionary entries to merge alongside /Length and /Filter.
     *   Used by Form XObjects to declare /Type /Subtype /BBox /Resources etc.
     */
    public static function of(string $content, ?Dictionary $extra = null): self
    {
        return new self($content, $extra);
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
            throw new PdfException('FlateDecode compression failed');
        }
        return $compressed;
    }

    /**
     * Returns the stream dict carrying extraDict + /Filter (without /Length).
     * The encryption path needs this to preserve extra entries while substituting
     * /Length to match the encrypted byte length.
     *
     * @internal
     */
    public function streamDict(): Dictionary
    {
        $dict = $this->extraDict ?? Dictionary::empty();
        return $dict->withEntry(Name::of('Filter'), Name::of('FlateDecode'));
    }

    /** @internal Test-only access to the pre-compression content. */
    public function rawContentForTest(): string
    {
        return $this->content;
    }

    public function toBytes(): string
    {
        $compressed = $this->compressedContent();
        $dict = ($this->extraDict ?? Dictionary::empty())
            ->withEntry(Name::of('Length'), PdfNumber::ofInt(strlen($compressed)))
            ->withEntry(Name::of('Filter'), Name::of('FlateDecode'));
        return $dict->toBytes() . "\nstream\n" . $compressed . "\nendstream";
    }
}
