<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\PdfEditor;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PdfSignExistingTest extends TestCase
{
    private function sourcePdf(): string
    {
        $doc = new Document();
        $doc->addPage();
        return $doc->output();
    }

    /** @return array{0: SigningCertificate, 1: string} */
    private function cred(): array
    {
        $gen = TestCertificate::generate();
        return [SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']), $gen['certPem']];
    }

    private function assertSignatureVerifies(string $openssl, string $bytes, string $certPem): void
    {
        if (preg_match('~/ByteRange \[0 (\d{10}) (\d{10}) (\d{10})\]~', $bytes, $m) !== 1) {
            self::fail('ByteRange not found');
        }
        $a = (int) $m[1];
        $start2 = (int) $m[2];
        $len2 = (int) $m[3];
        $signed = substr($bytes, 0, $a) . substr($bytes, $start2, $len2);
        $der = hex2bin(substr($bytes, $a + 1, ($start2 - 1) - ($a + 1)));
        self::assertNotFalse($der);
        $derF = (string) tempnam(sys_get_temp_dir(), 'der');
        $cntF = (string) tempnam(sys_get_temp_dir(), 'cnt');
        $crtF = (string) tempnam(sys_get_temp_dir(), 'crt');
        try {
            file_put_contents($derF, $der);
            file_put_contents($cntF, $signed);
            file_put_contents($crtF, $certPem);
            $p = new Process([$openssl, 'cms', '-verify', '-binary', '-inform', 'DER',
                '-in', $derF, '-content', $cntF, '-certfile', $crtF, '-noverify']);
            $p->run();
            self::assertSame(0, $p->getExitCode(), 'cms verify failed: ' . $p->getErrorOutput());
        } finally {
            @unlink($derF); @unlink($cntF); @unlink($crtF);
        }
    }

    public function testSignOpenedPdfCreatesVerifiableSignature(): void
    {
        $openssl = (new ExecutableFinder())->find('openssl');
        if ($openssl === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $source = $this->sourcePdf();
        [$cred, $certPem] = $this->cred();
        $pdf = PdfEditor::fromBytes($source);
        $pdf->sign($cred, field: 'SignatureExisting', reason: 'Approved');
        $bytes = $pdf->output();

        self::assertSame($source, substr($bytes, 0, strlen($source)), 'source preserved verbatim');
        self::assertStringContainsString('/FT /Sig', $bytes);
        self::assertStringContainsString('/SigFlags 3', $bytes);
        $this->assertSignatureVerifies($openssl, $bytes, $certPem);
    }

    public function testAddSignatureAutoNames(): void
    {
        if (!function_exists('openssl_pkcs12_read')) {
            self::markTestSkipped('openssl unavailable');
        }
        [$cred] = $this->cred();
        $pdf = PdfEditor::fromBytes($this->sourcePdf());
        self::assertSame($pdf, $pdf->addSignature($cred));
    }
}
