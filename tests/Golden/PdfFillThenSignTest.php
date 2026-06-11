<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\TextField;
use DragonOfMercy\PhpPdf\Pdf;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PdfFillThenSignTest extends TestCase
{
    private function formSource(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new TextField(20, 20, 80, 12, name: 'FullName'));
        return $doc->output();
    }

    public function testFillThenSignCoversFilledRevision(): void
    {
        $openssl = (new ExecutableFinder())->find('openssl');
        if ($openssl === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $source = $this->formSource();
        $gen = TestCertificate::generate();
        $cred = SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);

        $pdf = Pdf::fromBytes($source);
        $pdf->setField('FullName', 'Alice Example');
        $pdf->sign($cred, field: 'Approval');
        $bytes = $pdf->output();

        self::assertSame($source, substr($bytes, 0, strlen($source)), 'source preserved');

        if (preg_match('~/ByteRange \[0 (\d{10}) (\d{10}) (\d{10})\]~', $bytes, $m) !== 1) {
            self::fail('ByteRange not found');
        }
        $a = (int) $m[1];
        $start2 = (int) $m[2];
        $len2 = (int) $m[3];
        $coveredEnd = $start2 + $len2;
        self::assertGreaterThan(strlen($source), $coveredEnd, 'signature covers the edit revision');

        $signed = substr($bytes, 0, $a) . substr($bytes, $start2, $len2);
        $der = hex2bin(substr($bytes, $a + 1, ($start2 - 1) - ($a + 1)));
        self::assertNotFalse($der);
        $derF = (string) tempnam(sys_get_temp_dir(), 'der');
        $cntF = (string) tempnam(sys_get_temp_dir(), 'cnt');
        $crtF = (string) tempnam(sys_get_temp_dir(), 'crt');
        try {
            file_put_contents($derF, $der);
            file_put_contents($cntF, $signed);
            file_put_contents($crtF, $gen['certPem']);
            $p = new Process([$openssl, 'cms', '-verify', '-binary', '-inform', 'DER',
                '-in', $derF, '-content', $cntF, '-certfile', $crtF, '-noverify']);
            $p->run();
            self::assertSame(0, $p->getExitCode(), 'cms verify failed: ' . $p->getErrorOutput());
        } finally {
            @unlink($derF); @unlink($cntF); @unlink($crtF);
        }
    }
}
