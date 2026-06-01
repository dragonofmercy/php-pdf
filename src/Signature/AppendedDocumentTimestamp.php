<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;

/**
 * A document timestamp applied in its own incremental revision. The value dict
 * is a /DocTimeStamp (ETSI.RFC3161); fill() returns the RFC 3161 token over the
 * covered byte range.
 *
 * @internal
 */
final readonly class AppendedDocumentTimestamp implements AppendedRevision
{
    public function __construct(
        private DocumentTimestamp $timestamp,
        private string $fieldName,
    ) {}

    public function fieldName(): string
    {
        return $this->fieldName;
    }

    public function maxSignatureBytes(): int
    {
        return $this->timestamp->maxSignatureBytes;
    }

    public function valueDict(int $objectNumber): IndirectObject
    {
        return (new DocTimeStampDictionaryEmitter())->emit($this->timestamp->maxSignatureBytes, $objectNumber);
    }

    public function fill(string $signedData): string
    {
        $tsa = $this->timestamp->tsa;
        return $tsa->client->timestamp($tsa->hash->digest($signedData), $tsa->hash->oid());
    }
}
