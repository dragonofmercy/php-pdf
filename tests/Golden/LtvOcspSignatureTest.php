<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\SignatureField;
use DragonOfMercy\PhpPdf\Signature\Ltv\CertificateChain;
use DragonOfMercy\PhpPdf\Signature\Ltv\StaticValidationDataSource;
use DragonOfMercy\PhpPdf\Signature\Ltv\ValidationMaterial;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use DragonOfMercy\PhpPdf\Tests\Support\TestTsa;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * End-to-end acceptance for OCSP-based PAdES-LTV: sign a document, embed a /DSS
 * carrying the cert chain + an OCSP response (no CRL) plus a covering
 * /DocTimeStamp via enableLtv(), then validate with pyHanko. Under hard-fail
 * with no network fetching, revocation can only resolve from the embedded OCSP
 * response, so a pass proves genuine OCSP-LTV. Auto-skips when openssl / pyHanko
 * are absent.
 */
final class LtvOcspSignatureTest extends TestCase
{
    /**
     * @return array{bytes: string, rootPem: string}
     */
    private function buildOcspLtvDocument(): array
    {
        $pki = TestPki::issueWithOcsp();
        if ($pki === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl CLI/ext unavailable');
        }
        $cred = SigningCertificate::fromPkcs12Bytes($pki['leafP12'], $pki['password']);
        $material = ValidationMaterial::of(
            [
                CertificateChain::pemToDer($pki['leafPem']),
                CertificateChain::pemToDer($pki['rootPem']),
                CertificateChain::pemToDer($pki['ocspSignerPem']),
            ],
            [],
            [$pki['ocspResponseDer']],
        );

        $doc = new Document();
        $page = $doc->addPage();
        $page->field(SignatureField::visible(20, 20, 80, 20, name: 'sig'));
        $doc->sign($cred, field: 'sig', reason: 'LTV-OCSP');
        $doc->enableLtv(new StaticValidationDataSource($material), Tsa::withClient(new TestTsa()));

        return ['bytes' => $doc->output(), 'rootPem' => $pki['rootPem']];
    }

    public function testDssHasOcspAndNoCrl(): void
    {
        ['bytes' => $bytes] = $this->buildOcspLtvDocument();
        self::assertStringContainsString('/Type /DSS', $bytes);
        self::assertStringContainsString('/OCSPs', $bytes);
        self::assertStringNotContainsString('/CRLs', $bytes);
        self::assertStringContainsString('/DocTimeStamp', $bytes);
    }

    public function testPyHankoValidatesOcspLtv(): void
    {
        $python = 'C:/tmp/pdfsig-venv/Scripts/python.exe';
        if (!is_file($python)) {
            self::markTestSkipped('pyHanko venv unavailable');
        }
        ['bytes' => $bytes, 'rootPem' => $rootPem] = $this->buildOcspLtvDocument();
        $pdf = (string) tempnam(sys_get_temp_dir(), 'ltvo');
        $root = (string) tempnam(sys_get_temp_dir(), 'root');
        try {
            file_put_contents($pdf, $bytes);
            file_put_contents($root, $rootPem);
            $script = __DIR__ . '/assets/ltv-validate.py';
            $p = new Process([$python, $script, $pdf, $root]);
            $p->run();
            self::assertStringContainsString('OK', $p->getOutput(),
                'pyHanko OCSP-LTV validation failed: ' . $p->getOutput() . $p->getErrorOutput());
            self::assertSame(0, $p->getExitCode());
        } finally {
            @unlink($pdf);
            @unlink($root);
        }
    }
}
