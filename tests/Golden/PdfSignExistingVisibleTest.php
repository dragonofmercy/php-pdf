<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Pdf;
use DragonOfMercy\PhpPdf\Signature\SignatureAppearance;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PdfSignExistingVisibleTest extends TestCase
{
    private function source(): string
    {
        $doc = new Document();
        $doc->addPage();
        return $doc->output();
    }

    private function cred(): SigningCertificate
    {
        $gen = TestCertificate::generate();
        return SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);
    }

    public function testVisibleSignatureHasNonZeroRectAndAppearance(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $pdf = Pdf::fromBytes($this->source());
        $pdf->sign($this->cred(), field: 'VisSig',
            appearance: new SignatureAppearance(0, 20.0, 20.0, 120.0, 40.0, 'Signed by Test'));
        $bytes = $pdf->output();

        self::assertStringContainsString('/AP', $bytes);
        self::assertStringContainsString('/FT /Sig', $bytes);
        self::assertStringContainsString('/BaseFont /Helvetica', $bytes);
        self::assertDoesNotMatchRegularExpression('~/Rect \[0 0 0 0\]~', $bytes);
    }

    public function testVisibleSignaturePageOutOfRangeThrows(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $pdf = Pdf::fromBytes($this->source());
        $pdf->sign($this->cred(), field: 'VisSig',
            appearance: new SignatureAppearance(5, 20.0, 20.0, 120.0, 40.0, 'x'));
        $this->expectException(PdfException::class);
        $pdf->output();
    }

    public function testQpdfCleanVisible(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('qpdf or openssl unavailable');
        }
        $pdf = Pdf::fromBytes($this->source());
        $pdf->sign($this->cred(), field: 'VisSig',
            appearance: new SignatureAppearance(0, 20.0, 20.0, 120.0, 40.0, 'Signed'));
        $file = (string) tempnam(sys_get_temp_dir(), 'pdfvis');
        try {
            file_put_contents($file, $pdf->output());
            $p = new Process([$qpdf, '--check', $file]);
            $p->run();
            self::assertNotSame(2, $p->getExitCode(),
                'qpdf structural errors: ' . $p->getOutput() . $p->getErrorOutput());
        } finally {
            @unlink($file);
        }
    }
}
