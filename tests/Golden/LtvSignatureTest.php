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
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * End-to-end acceptance for the PAdES-LTV (DSS + DocTimeStamp) feature: sign a
 * document, embed a /DSS (leaf+root certs + CRL) plus a covering /DocTimeStamp
 * via enableLtv(), then validate the result with openssl, qpdf and pyHanko.
 *
 * pyHanko validates the embedded DSS revocation under hard-fail with no network
 * fetching (see tests/Golden/assets/ltv-validate.py); the harness accepts the
 * strict "trusted" verdict, falling back to an explicit path-to-root + DSS-CRL
 * proof only when pyHanko declines "trusted" for the Adobe-style
 * adbe.pkcs7.detached SubFilter. Neither acceptance can pass on a non-LTV file.
 */
final class LtvSignatureTest extends TestCase
{
    /**
     * @return array{bytes: string, rootPem: string, leafPem: string}
     */
    private function buildLtvDocument(): array
    {
        $pki = TestPki::issueWithCrl();
        if ($pki === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl CLI/ext unavailable');
        }
        $cred = SigningCertificate::fromPkcs12Bytes($pki['leafP12'], $pki['password']);
        $leafDer = CertificateChain::pemToDer($pki['leafPem']);
        $rootDer = CertificateChain::pemToDer($pki['rootPem']);
        $material = ValidationMaterial::of([$leafDer, $rootDer], [$pki['crlDer']]);

        $doc = new Document();
        $page = $doc->addPage();
        $page->field(SignatureField::visible(20, 20, 80, 20, name: 'sig'));
        $doc->sign($cred, field: 'sig', reason: 'LTV');
        $doc->enableLtv(new StaticValidationDataSource($material), Tsa::withClient(new TestTsa()));
        $bytes = $doc->output();

        return ['bytes' => $bytes, 'rootPem' => $pki['rootPem'], 'leafPem' => $pki['leafPem']];
    }

    public function testDssAndDocTimeStampPresent(): void
    {
        ['bytes' => $bytes] = $this->buildLtvDocument();
        self::assertStringContainsString('/Type /DSS', $bytes);
        self::assertStringContainsString('/DocTimeStamp', $bytes);
        self::assertSame(2, substr_count($bytes, '/ByteRange'));
    }

    public function testOriginalSignatureStillVerifies(): void
    {
        $openssl = (new ExecutableFinder())->find('openssl');
        if ($openssl === null) {
            self::markTestSkipped('openssl CLI unavailable');
        }
        ['bytes' => $bytes, 'leafPem' => $leafPem] = $this->buildLtvDocument();
        if (preg_match('~/ByteRange \[0 (\d{10}) (\d{10}) (\d{10})\]~', $bytes, $m) !== 1) {
            self::fail('first signature ByteRange not found');
        }
        $a = (int) $m[1];
        $start2 = (int) $m[2];
        $len2 = (int) $m[3];
        $signed = substr($bytes, 0, $a) . substr($bytes, $start2, $len2);
        $hex = substr($bytes, $a + 1, ($start2 - 1) - ($a + 1));
        $der = hex2bin($hex);
        self::assertNotFalse($der);
        $derF = (string) tempnam(sys_get_temp_dir(), 'der');
        $cntF = (string) tempnam(sys_get_temp_dir(), 'cnt');
        $crtF = (string) tempnam(sys_get_temp_dir(), 'crt');
        try {
            file_put_contents($derF, $der);
            file_put_contents($cntF, $signed);
            file_put_contents($crtF, $leafPem);
            $p = new Process([$openssl, 'cms', '-verify', '-binary', '-inform', 'DER',
                '-in', $derF, '-content', $cntF, '-certfile', $crtF, '-noverify']);
            $p->run();
            self::assertSame(0, $p->getExitCode(), 'cms verify failed: ' . $p->getErrorOutput());
        } finally {
            @unlink($derF);
            @unlink($cntF);
            @unlink($crtF);
        }
    }

    public function testQpdfClean(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf unavailable');
        }
        ['bytes' => $bytes] = $this->buildLtvDocument();
        $pdf = (string) tempnam(sys_get_temp_dir(), 'ltv');
        try {
            file_put_contents($pdf, $bytes);
            $p = new Process([$qpdf, '--check', $pdf]);
            $p->run();
            self::assertNotSame(2, $p->getExitCode(),
                'qpdf structural errors: ' . $p->getOutput() . $p->getErrorOutput());
        } finally {
            @unlink($pdf);
        }
    }

    public function testPyHankoValidatesLtv(): void
    {
        $python = 'C:/tmp/pdfsig-venv/Scripts/python.exe';
        if (!is_file($python)) {
            self::markTestSkipped('pyHanko venv unavailable');
        }
        ['bytes' => $bytes, 'rootPem' => $rootPem] = $this->buildLtvDocument();
        $pdf = (string) tempnam(sys_get_temp_dir(), 'ltv');
        $root = (string) tempnam(sys_get_temp_dir(), 'root');
        try {
            file_put_contents($pdf, $bytes);
            file_put_contents($root, $rootPem);
            $script = __DIR__ . '/assets/ltv-validate.py';
            $p = new Process([$python, $script, $pdf, $root]);
            $p->run();
            self::assertStringContainsString('OK', $p->getOutput(),
                'pyHanko LTV validation failed: ' . $p->getOutput() . $p->getErrorOutput());
            self::assertSame(0, $p->getExitCode());
        } finally {
            @unlink($pdf);
            @unlink($root);
        }
    }
}
