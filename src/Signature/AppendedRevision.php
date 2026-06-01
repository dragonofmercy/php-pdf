<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;

/**
 * One incremental revision appended after the base document: an additional
 * approval signature or a document timestamp. Each owns the field it adds, the
 * value dictionary placed under that field's /V, and the DER that fills the
 * value dict's /Contents.
 *
 * @internal
 */
interface AppendedRevision
{
    public function fieldName(): string;

    public function maxSignatureBytes(): int;

    public function valueDict(int $objectNumber): IndirectObject;

    /**
     * @param string $signedData the byte range the revision covers
     * @return string DER to embed in /Contents (a CMS signature or an RFC 3161 token)
     */
    public function fill(string $signedData): string;
}
