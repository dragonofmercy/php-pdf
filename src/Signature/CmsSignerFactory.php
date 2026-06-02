<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Signature\Cades\CadesSigner;

/**
 * Returns the CmsSigner implementing a given SignatureFormat.
 *
 * @internal
 */
final readonly class CmsSignerFactory
{
    public static function for(SignatureFormat $format): CmsSigner
    {
        return match ($format) {
            SignatureFormat::Pkcs7Detached => new Pkcs7Signer(),
            SignatureFormat::EtsiCadesDetached => new CadesSigner(),
        };
    }
}
