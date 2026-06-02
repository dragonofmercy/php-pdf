<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Cades;

use DragonOfMercy\PhpPdf\Signature\Asn1\CertificateFields;
use DragonOfMercy\PhpPdf\Signature\Asn1\Der;

/**
 * Builds the ESS SigningCertificateV2 attribute value (RFC 5035) for a signer
 * certificate: a SHA-256 hash of the whole certificate (certHash) plus an
 * IssuerSerial that names the issuer and serial. The SHA-256 hashAlgorithm is
 * the ESSCertIDv2 DEFAULT and is therefore omitted from the DER. The returned
 * bytes are the SigningCertificateV2 value; CmsSignedAttributes wraps it in the
 * attribute SET.
 *
 * @internal
 */
final readonly class SigningCertificateV2Attribute
{
    public static function build(string $certificateDer): string
    {
        $fields = CertificateFields::fromDer($certificateDer);
        $certHash = Der::octetString(hash('sha256', $certificateDer, true));

        $generalName = Der::contextConstructed(4, $fields->issuerNameDer());
        $generalNames = Der::sequence($generalName);
        $serialNumber = Der::tlv(0x02, $fields->serialNumber());
        $issuerSerial = Der::sequence($generalNames, $serialNumber);

        $essCertIdV2 = Der::sequence($certHash, $issuerSerial);

        return Der::sequence(Der::sequence($essCertIdV2));
    }
}
