<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Ltv;

/**
 * A ValidationDataSource that returns material handed to it at construction,
 * ignoring the chain argument. Used for offline tests and for callers who
 * already obtained the certificates and CRLs themselves.
 *
 * @internal
 */
final readonly class StaticValidationDataSource implements ValidationDataSource
{
    public function __construct(private ValidationMaterial $material) {}

    public function collect(array $chainPem): ValidationMaterial
    {
        return $this->material;
    }
}
