<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Cades;

use DragonOfMercy\PhpPdf\Signature\Asn1\Der;

/**
 * Builds the CAdES signed attributes for a SignerInfo: contentType (id-data),
 * messageDigest, and signingCertificateV2. Per RFC 5652 5.4 the attributes are
 * signed under an EXPLICIT SET OF (0x31) tag but embedded in the SignerInfo
 * under the [0] IMPLICIT (0xA0) tag, over the same content; DER requires the
 * SET OF elements sorted ascending bytewise.
 *
 * @internal
 */
final readonly class CmsSignedAttributes
{
    private const string OID_CONTENT_TYPE = '1.2.840.113549.1.9.3';
    private const string OID_MESSAGE_DIGEST = '1.2.840.113549.1.9.4';
    private const string OID_ID_DATA = '1.2.840.113549.1.7.1';
    private const string OID_SIGNING_CERTIFICATE_V2 = '1.2.840.113549.1.9.16.2.47';

    /** @var string the DER content of the sorted attributes, without the outer tag */
    private string $sortedContent;

    public function __construct(string $messageDigest, string $signerCertificateDer)
    {
        $contentType = Der::sequence(
            Der::oid(self::OID_CONTENT_TYPE),
            Der::set(Der::oid(self::OID_ID_DATA)),
        );
        $messageDigestAttr = Der::sequence(
            Der::oid(self::OID_MESSAGE_DIGEST),
            Der::set(Der::octetString($messageDigest)),
        );
        $signingCertificate = Der::sequence(
            Der::oid(self::OID_SIGNING_CERTIFICATE_V2),
            Der::set(SigningCertificateV2Attribute::build($signerCertificateDer)),
        );

        $attributes = [$contentType, $messageDigestAttr, $signingCertificate];
        sort($attributes);
        $this->sortedContent = implode('', $attributes);
    }

    /**
     * The bytes that are signed: the attributes under an EXPLICIT SET OF tag.
     */
    public function signingForm(): string
    {
        return Der::tlv(0x31, $this->sortedContent);
    }

    /**
     * The bytes embedded in the SignerInfo: the same content under [0] IMPLICIT.
     */
    public function embeddedForm(): string
    {
        return Der::contextConstructed(0, $this->sortedContent);
    }
}
