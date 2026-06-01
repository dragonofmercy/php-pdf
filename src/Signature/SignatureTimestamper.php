<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\Asn1\Der;

/**
 * Embeds an RFC 3161 TimeStampToken as an id-aa-timeStampToken unsigned
 * attribute inside the single SignerInfo of a CMS SignedData (the signature
 * produced by Pkcs7Signer), upgrading it to PAdES-B-T. The timestamp covers
 * the SignerInfo signature value per RFC 3161 / RFC 5126.
 *
 * openssl_cms_sign cannot add unsigned attributes, so the token is spliced in
 * by editing the DER and recomputing the definite-length prefixes outward.
 */
final readonly class SignatureTimestamper
{
    private const string ID_AA_TIMESTAMP_TOKEN = '1.2.840.113549.1.9.16.2.14';

    public function __construct(private TsaHashAlgorithm $hash) {}

    public function timestamp(string $cms, TsaClient $client): string
    {
        // Navigate ContentInfo -> [0] EXPLICIT -> SignedData -> signerInfos SET -> SignerInfo.
        $contentInfo = Der::readHeader($cms, 0);
        $ciOid = Der::readHeader($cms, $contentInfo['valueStart']);
        $explicit = Der::readHeader($cms, $ciOid['end']);
        if ($explicit['tag'] !== 0xA0) {
            throw new PdfException('CMS: expected [0] EXPLICIT content');
        }
        $signedData = Der::readHeader($cms, $explicit['valueStart']);
        if ($signedData['tag'] !== 0x30) {
            throw new PdfException('CMS: SignedData is not a SEQUENCE');
        }

        // signerInfos is the last child of SignedData and is a SET (0x31).
        $signerInfos = $this->findSignerInfosSet($cms, $signedData);

        // First (and only) SignerInfo.
        $signerInfo = Der::readHeader($cms, $signerInfos['valueStart']);
        if ($signerInfo['tag'] !== 0x30) {
            throw new PdfException('CMS: SignerInfo is not a SEQUENCE');
        }
        if ($signerInfo['end'] !== $signerInfos['end']) {
            throw new PdfException('CMS: expected exactly one SignerInfo');
        }

        // Within SignerInfo, the signature is the single top-level OCTET STRING.
        $signatureValue = $this->readSignatureValue($cms, $signerInfo);

        // Request the token over the hash of the signature value.
        $imprint = $this->hash->digest($signatureValue);
        $token = $client->timestamp($imprint, $this->hash->oid());

        // Build unsignedAttrs [1] IMPLICIT SET OF Attribute.
        $attribute = Der::sequence(
            Der::oid(self::ID_AA_TIMESTAMP_TOKEN),
            Der::set($token),
        );
        $unsignedAttrs = Der::contextConstructed(1, $attribute);

        // Splice unsignedAttrs at the end of SignerInfo's content, then rebuild
        // the nested SEQUENCE/SET length prefixes outward.
        $newSignerInfo = Der::sequence(
            substr($cms, $signerInfo['valueStart'], $signerInfo['length']) . $unsignedAttrs,
        );
        $newSignerInfos = Der::set($newSignerInfo);

        // SignedData content up to (not including) the signerInfos TLV, then the
        // rebuilt SET. tlvStart() recovers the byte offset of the SET's tag.
        $signerInfosTlvStart = $this->tlvStart($signerInfos);
        $signedDataHead = substr($cms, $signedData['valueStart'], $signerInfosTlvStart - $signedData['valueStart']);
        $newSignedData = Der::sequence($signedDataHead . $newSignerInfos);

        // Rebuild [0] EXPLICIT and the outer ContentInfo (contentType OID + content).
        $newExplicit = Der::contextConstructed(0, $newSignedData);
        $contentTypeTlv = substr($cms, $contentInfo['valueStart'], $ciOid['end'] - $contentInfo['valueStart']);
        return Der::sequence($contentTypeTlv . $newExplicit);
    }

    /**
     * @param array{tag: int, length: int, valueStart: int, end: int} $signedData
     * @return array{tag: int, length: int, valueStart: int, end: int}
     */
    private function findSignerInfosSet(string $cms, array $signedData): array
    {
        $offset = $signedData['valueStart'];
        $last = null;
        while ($offset < $signedData['end']) {
            $child = Der::readHeader($cms, $offset);
            $last = $child;
            $offset = $child['end'];
        }
        if ($last === null || $last['tag'] !== 0x31) {
            throw new PdfException('CMS: signerInfos SET not found at end of SignedData');
        }
        return $last;
    }

    /**
     * @param array{tag: int, length: int, valueStart: int, end: int} $signerInfo
     */
    private function readSignatureValue(string $cms, array $signerInfo): string
    {
        // The signature is the only top-level OCTET STRING in SignerInfo
        // (sid is a SEQUENCE or [0], signedAttrs is [0], the algorithm
        // identifiers are SEQUENCEs). Take the last top-level OCTET STRING.
        $offset = $signerInfo['valueStart'];
        $signature = null;
        while ($offset < $signerInfo['end']) {
            $child = Der::readHeader($cms, $offset);
            if ($child['tag'] === 0x04) {
                $signature = $child;
            }
            $offset = $child['end'];
        }
        if ($signature === null) {
            throw new PdfException('CMS: SignerInfo signature OCTET STRING not found');
        }
        return substr($cms, $signature['valueStart'], $signature['length']);
    }

    /** @param array{tag: int, length: int, valueStart: int, end: int} $header */
    private function tlvStart(array $header): int
    {
        return $header['valueStart'] - $this->headerWidth($header['length']);
    }

    private function headerWidth(int $length): int
    {
        if ($length < 0x80) {
            return 2;
        }
        $octets = 0;
        $n = $length;
        while ($n > 0) {
            $octets++;
            $n >>= 8;
        }
        return 2 + $octets;
    }
}
