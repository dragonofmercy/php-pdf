<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Asn1;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Extracts the four X.509 fields the OCSP CertID and CAdES attributes need from
 * a DER certificate: the serialNumber content bytes, the full DER of the issuer
 * Name (for IssuerSerial / issuerNameHash), the full DER of the subject Name
 * (hashed for issuerNameHash), and the raw subjectPublicKey bytes (hashed for
 * issuerKeyHash, per RFC 6960 4.1.1, excluding tag, length and the unused-bits
 * octet). Walks TBSCertificate field by field; not a general X.509 parser.
 *
 * @internal
 */
final readonly class CertificateFields
{
    private function __construct(
        private string $serialNumber,
        private string $issuerNameDer,
        private string $subjectNameDer,
        private string $subjectPublicKeyBytes,
    ) {}

    public static function fromDer(string $der): self
    {
        $cert = Der::readHeader($der, 0);
        if ($cert['tag'] !== 0x30) {
            throw new PdfException('Certificate is not a DER SEQUENCE');
        }
        $tbs = Der::readHeader($der, $cert['valueStart']);
        if ($tbs['tag'] !== 0x30) {
            throw new PdfException('TBSCertificate is not a DER SEQUENCE');
        }

        $cursor = $tbs['valueStart'];
        $field = Der::readHeader($der, $cursor);
        if ($field['tag'] === 0xA0) {
            $cursor = $field['end'];
            $field = Der::readHeader($der, $cursor);
        }
        if ($field['tag'] !== 0x02) {
            throw new PdfException('TBSCertificate serialNumber not found');
        }
        $serialNumber = substr($der, $field['valueStart'], $field['length']);

        $cursor = $field['end'];
        $cursor = Der::readHeader($der, $cursor)['end'];   // signature AlgorithmIdentifier

        $issuer = Der::readHeader($der, $cursor);          // issuer Name
        if ($issuer['tag'] !== 0x30) {
            throw new PdfException('TBSCertificate issuer Name not found');
        }
        $issuerNameDer = substr($der, $issuer['start'], $issuer['end'] - $issuer['start']);

        $cursor = Der::readHeader($der, $issuer['end'])['end'];   // validity

        $subject = Der::readHeader($der, $cursor);
        if ($subject['tag'] !== 0x30) {
            throw new PdfException('TBSCertificate subject Name not found');
        }
        $subjectNameDer = substr($der, $subject['start'], $subject['end'] - $subject['start']);

        $spki = Der::readHeader($der, $subject['end']);
        if ($spki['tag'] !== 0x30) {
            throw new PdfException('SubjectPublicKeyInfo not found');
        }
        $algo = Der::readHeader($der, $spki['valueStart']);
        $bitString = Der::readHeader($der, $algo['end']);
        if ($bitString['tag'] !== 0x03 || $bitString['length'] < 1) {
            throw new PdfException('subjectPublicKey BIT STRING not found');
        }
        $subjectPublicKeyBytes = substr($der, $bitString['valueStart'] + 1, $bitString['length'] - 1);

        return new self($serialNumber, $issuerNameDer, $subjectNameDer, $subjectPublicKeyBytes);
    }

    public function serialNumber(): string
    {
        return $this->serialNumber;
    }

    public function issuerNameDer(): string
    {
        return $this->issuerNameDer;
    }

    public function subjectNameDer(): string
    {
        return $this->subjectNameDer;
    }

    public function subjectPublicKeyBytes(): string
    {
        return $this->subjectPublicKeyBytes;
    }
}
