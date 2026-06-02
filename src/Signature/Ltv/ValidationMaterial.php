<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Ltv;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Validation material for a Document Security Store: raw DER certificates and
 * CRLs (and, reserved for a later OCSP phase, OCSP responses). Immutable.
 *
 * @internal
 */
final readonly class ValidationMaterial
{
    /**
     * @param list<string> $certificates DER-encoded X.509 certificates
     * @param list<string> $crls DER-encoded CRLs
     * @param list<string> $ocsps DER-encoded OCSP responses (reserved; empty in the CRL phase)
     */
    private function __construct(
        public array $certificates,
        public array $crls,
        public array $ocsps,
    ) {}

    /**
     * @param list<string> $certificates
     * @param list<string> $crls
     * @param list<string> $ocsps
     */
    public static function of(array $certificates, array $crls, array $ocsps = []): self
    {
        foreach ($certificates as $der) {
            if ($der === '') {
                throw new PdfException('ValidationMaterial certificate entry cannot be empty');
            }
        }
        foreach ($crls as $der) {
            if ($der === '') {
                throw new PdfException('ValidationMaterial CRL entry cannot be empty');
            }
        }
        foreach ($ocsps as $der) {
            if ($der === '') {
                throw new PdfException('ValidationMaterial OCSP entry cannot be empty');
            }
        }
        return new self($certificates, $crls, $ocsps);
    }

    /**
     * Returns a new instance with $other's entries appended, dropping exact
     * duplicates while preserving first-seen order.
     */
    public function merge(self $other): self
    {
        return new self(
            self::dedupe([...$this->certificates, ...$other->certificates]),
            self::dedupe([...$this->crls, ...$other->crls]),
            self::dedupe([...$this->ocsps, ...$other->ocsps]),
        );
    }

    /**
     * @param list<string> $items
     * @return list<string>
     */
    private static function dedupe(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = md5($item);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $item;
            }
        }
        return $out;
    }
}
