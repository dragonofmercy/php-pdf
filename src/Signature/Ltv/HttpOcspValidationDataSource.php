<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Ltv;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\Asn1\OcspRequestBuilder;

/**
 * OCSP-based ValidationDataSource, peer to HttpCrlValidationDataSource: embeds
 * every certificate in the chain and, for each non-root certificate, builds an
 * OCSPRequest against its issuer (the next cert in the chain), fetches the
 * response from the responder named in the certificate's AIA extension, and
 * embeds it. Network or parse failures throw rather than dropping revocation
 * data. The OcspClient is injectable so tests can run offline.
 *
 * @internal
 */
final readonly class HttpOcspValidationDataSource implements ValidationDataSource
{
    private OcspClient $client;

    public function __construct(?OcspClient $client = null)
    {
        $this->client = $client ?? new HttpOcspClient();
    }

    /**
     * @param list<string> $chainPem the signer certificate first, then its
     *        issuer chain, all PEM-encoded
     */
    public function collect(array $chainPem): ValidationMaterial
    {
        $certs = [];
        $ocsps = [];
        foreach ($chainPem as $index => $pem) {
            $der = CertificateChain::pemToDer($pem);
            $certs[] = $der;
            if (CertificateChain::isSelfSigned($pem)) {
                continue;
            }
            $issuerPem = $chainPem[$index + 1] ?? null;
            if ($issuerPem === null) {
                throw new PdfException('Certificate has no issuer in chain; cannot build OCSP request');
            }
            $urls = CertificateChain::ocspUrls($pem);
            if ($urls === []) {
                throw new PdfException('Certificate has no OCSP responder URL (AIA); cannot make it LTV');
            }
            $request = OcspRequestBuilder::build($der, CertificateChain::pemToDer($issuerPem));
            $ocsps[] = $this->client->request($urls[0], $request);
        }
        return ValidationMaterial::of($certs, [], $ocsps);
    }
}
