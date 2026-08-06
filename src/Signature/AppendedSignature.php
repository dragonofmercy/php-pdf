<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;

/**
 * An approval signature applied in its own incremental revision. The value dict
 * is a /Sig (adbe.pkcs7.detached); fill() produces a detached CMS via
 * Pkcs7Signer, optionally wrapped with an RFC 3161 signature timestamp.
 *
 * @internal
 */
final readonly class AppendedSignature implements AppendedRevision
{
    public function __construct(private Signature $signature) {}

    public function fieldName(): string
    {
        return $this->signature->fieldName;
    }

    public function maxSignatureBytes(): int
    {
        return $this->signature->maxSignatureBytes;
    }

    public function valueDict(int $objectNumber): IndirectObject
    {
        return (new SignatureDictionaryEmitter())->emit($this->signature, $objectNumber);
    }

    public function fill(string $signedData): string
    {
        $der = $this->signature->format->signer()->sign($signedData, $this->signature->certificate);
        if ($this->signature->tsa !== null) {
            $der = (new SignatureTimestamper($this->signature->tsa->hash))
                ->timestamp($der, $this->signature->tsa->client);
        }
        return $der;
    }
}
