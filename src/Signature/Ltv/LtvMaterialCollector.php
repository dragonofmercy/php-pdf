<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Ltv;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;

/**
 * Collects LTV validation material (certificate chains + CRLs/OCSPs) for a set
 * of signing certificates and optional TSA chains, with the empty-result
 * guards. Shared by Document::enableLtv() and PdfEditor::enableLtv().
 *
 * @internal
 */
final readonly class LtvMaterialCollector
{
    /**
     * @param list<SigningCertificate> $signingCertificates
     * @param list<list<string>> $timestampCertificateChains
     */
    public static function collect(
        ValidationDataSource $resolver,
        array $signingCertificates,
        array $timestampCertificateChains,
    ): ValidationMaterial {
        $material = ValidationMaterial::of([], []);
        foreach ($signingCertificates as $credential) {
            $material = $material->merge($resolver->collect(CertificateChain::chainPem($credential)));
        }
        foreach ($timestampCertificateChains as $tsaChainPem) {
            $material = $material->merge($resolver->collect($tsaChainPem));
        }
        if ($material->certificates === []) {
            throw new PdfException('enableLtv: the validation data source returned no certificates');
        }
        if ($material->crls === [] && $material->ocsps === []) {
            throw new PdfException('enableLtv: the validation data source returned no CRLs or OCSP responses');
        }
        return $material;
    }
}
