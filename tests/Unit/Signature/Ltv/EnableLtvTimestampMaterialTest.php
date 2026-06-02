<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Ltv;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\SignatureField;
use DragonOfMercy\PhpPdf\Signature\Ltv\CertificateChain;
use DragonOfMercy\PhpPdf\Signature\Ltv\ValidationDataSource;
use DragonOfMercy\PhpPdf\Signature\Ltv\ValidationMaterial;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

final class EnableLtvTimestampMaterialTest extends TestCase
{
    public function testTimestampChainMaterialReachesTheDss(): void
    {
        $pki = TestPki::issueTsaWithCrl();
        if ($pki === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $cred = SigningCertificate::fromPkcs12Bytes($pki['signerP12'], $pki['password']);
        $signerDer = CertificateChain::pemToDer($pki['signerPem']);
        $tsaDer = CertificateChain::pemToDer($pki['tsaPem']);
        $rootDer = CertificateChain::pemToDer($pki['rootPem']);

        $seenChains = [];
        $source = new class ($seenChains, $signerDer, $tsaDer, $rootDer, $pki['crlDer'], $pki['tsaPem']) implements ValidationDataSource {
            /** @param list<list<string>> $seen */
            public function __construct(
                public array &$seen,
                private string $signerDer,
                private string $tsaDer,
                private string $rootDer,
                private string $crlDer,
                private string $tsaPem,
            ) {}
            public function collect(array $chainPem): ValidationMaterial
            {
                $this->seen[] = $chainPem;
                $isTsa = isset($chainPem[0]) && $chainPem[0] === $this->tsaPem;
                $first = $isTsa ? $this->tsaDer : $this->signerDer;
                return ValidationMaterial::of([$first, $this->rootDer], [$this->crlDer]);
            }
        };

        $doc = new Document();
        $page = $doc->addPage();
        $page->field(SignatureField::visible(20, 20, 80, 20, name: 'sig'));
        $doc->sign($cred, field: 'sig', reason: 'B-LTA');
        $doc->enableLtv($source, null, [[$pki['tsaPem'], $pki['rootPem']]]);
        $bytes = $doc->output();

        self::assertContains([$pki['tsaPem'], $pki['rootPem']], $seenChains);
        self::assertStringContainsString($tsaDer, $bytes);
    }
}
