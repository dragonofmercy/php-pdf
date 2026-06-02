<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

/**
 * The CMS signature profile written to a signature dictionary's /SubFilter and
 * selecting the CMS builder: the Adobe-style detached PKCS#7 (default) or the
 * strict ETSI.CAdES detached profile (CAdES signed attributes incl.
 * signingCertificateV2).
 */
enum SignatureFormat
{
    case Pkcs7Detached;
    case EtsiCadesDetached;

    public function subFilter(): string
    {
        return match ($this) {
            self::Pkcs7Detached => 'adbe.pkcs7.detached',
            self::EtsiCadesDetached => 'ETSI.CAdES.detached',
        };
    }
}
