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
use DragonOfMercy\PhpPdf\Tests\Support\RevocableTestTsa;
use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * End-to-end acceptance for PAdES-B-LTA: sign a document and enableLtv() with a
 * DSS carrying the signer cert + the TSA cert + root + a covering CRL, plus a
 * /DocTimeStamp produced by a real RFC 3161 TSA (RevocableTestTsa). pyHanko then
 * validates - from the embedded DSS only, under hard-fail with no fetching -
 * BOTH the signature AND the document timestamp to the trust root. A pass proves
 * the archive timestamp is itself long-term validatable. Auto-skips when openssl
 * / pyHanko are absent.
 */
final class BltaSignatureTest extends TestCase
{
    /**
     * @return array{bytes: string, rootPem: string}
     */
    private function buildBltaDocument(): array
    {
        $pki = TestPki::issueTsaWithCrl();
        if ($pki === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl CLI/ext unavailable');
        }
        $cred = SigningCertificate::fromPkcs12Bytes($pki['signerP12'], $pki['password']);
        $material = ValidationMaterial::of(
            [
                CertificateChain::pemToDer($pki['signerPem']),
                CertificateChain::pemToDer($pki['tsaPem']),
                CertificateChain::pemToDer($pki['rootPem']),
            ],
            [$pki['crlDer']],
        );

        $doc = new Document();
        $page = $doc->addPage();
        $page->field(SignatureField::visible(20, 20, 80, 20, name: 'sig'));
        $doc->sign($cred, field: 'sig', reason: 'B-LTA');
        $doc->enableLtv(new StaticValidationDataSource($material), Tsa::withClient(new RevocableTestTsa($pki['dir'])));

        return ['bytes' => $doc->output(), 'rootPem' => $pki['rootPem']];
    }

    public function testDssAndDocTimeStampPresent(): void
    {
        ['bytes' => $bytes] = $this->buildBltaDocument();
        self::assertStringContainsString('/Type /DSS', $bytes);
        self::assertStringContainsString('/DocTimeStamp', $bytes);
    }

    public function testPyHankoValidatesBlta(): void
    {
        $python = 'C:/tmp/pdfsig-venv/Scripts/python.exe';
        if (!is_file($python)) {
            self::markTestSkipped('pyHanko venv unavailable');
        }
        ['bytes' => $bytes, 'rootPem' => $rootPem] = $this->buildBltaDocument();
        $pdf = (string) tempnam(sys_get_temp_dir(), 'blta');
        $root = (string) tempnam(sys_get_temp_dir(), 'root');
        try {
            file_put_contents($pdf, $bytes);
            file_put_contents($root, $rootPem);
            $script = __DIR__ . '/assets/blta-validate.py';
            $p = new Process([$python, $script, $pdf, $root]);
            $p->run();
            self::assertStringContainsString('OK', $p->getOutput(),
                'pyHanko B-LTA validation failed: ' . $p->getOutput() . $p->getErrorOutput());
            self::assertSame(0, $p->getExitCode());
        } finally {
            @unlink($pdf);
            @unlink($root);
        }
    }
}
