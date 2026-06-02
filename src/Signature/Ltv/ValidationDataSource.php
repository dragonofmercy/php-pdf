<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Ltv;

/**
 * Supplies the validation material (certificates + revocation data) needed to
 * make a set of signing certificates long-term validatable. The network seam:
 * the default implementation fetches over HTTP, tests inject canned material.
 * Mirrors the TsaClient / HttpTsaClient split used by the timestamp path.
 */
interface ValidationDataSource
{
    /**
     * @param list<string> $chainPem the signer certificate first, then its
     *        issuer chain, all PEM-encoded
     */
    public function collect(array $chainPem): ValidationMaterial;
}
