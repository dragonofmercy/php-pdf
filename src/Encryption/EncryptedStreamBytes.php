<?php

declare(strict_types=1);

namespace PhpPdf\Encryption;

use PhpPdf\Writer\Object\Dictionary;
use PhpPdf\Writer\Object\Name;
use PhpPdf\Writer\Object\PdfNumber;
use PhpPdf\Writer\Object\PdfObject;

/**
 * A pre-encrypted stream body. Emits the frozen byte payload between
 * `stream\n` and `\nendstream` verbatim, with a correct /Length. Supports
 * fluent withers to add /Type and /Subtype entries after construction.
 *
 * @internal
 */
final class EncryptedStreamBytes implements PdfObject
{
    public function __construct(
        private Dictionary $dict,
        private readonly string $encryptedContent,
    ) {}

    public function withType(string $type): self
    {
        $clone = clone $this;
        $clone->dict = $clone->dict->withEntry(Name::of('Type'), Name::of($type));
        return $clone;
    }

    public function withSubtype(string $subtype): self
    {
        $clone = clone $this;
        $clone->dict = $clone->dict->withEntry(Name::of('Subtype'), Name::of($subtype));
        return $clone;
    }

    public function toBytes(): string
    {
        $dictWithLength = $this->dict->withEntry(
            Name::of('Length'),
            PdfNumber::ofInt(strlen($this->encryptedContent)),
        );
        return $dictWithLength->toBytes() . "\nstream\n" . $this->encryptedContent . "\nendstream";
    }
}
