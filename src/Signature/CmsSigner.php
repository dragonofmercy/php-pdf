<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

/**
 * Produces a detached CMS / PKCS#7 signature (DER) over the given bytes using
 * the supplied credential. Implementations: Pkcs7Signer (openssl_cms_sign,
 * adbe.pkcs7.detached) and CadesSigner (hand-built, ETSI.CAdES.detached).
 */
interface CmsSigner
{
    public function sign(string $data, SigningCertificate $certificate): string;
}
