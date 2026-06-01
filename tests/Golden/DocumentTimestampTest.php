<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DateTimeImmutable;
use DateTimeZone;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\SignatureField;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestTsa;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class DocumentTimestampTest extends TestCase
{
    public function testStandaloneTimestampStructure(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $doc = new Document();
        $doc->addPage();
        $doc->addDocumentTimestamp(Tsa::withClient(new TestTsa()));
        $bytes = $doc->output();

        self::assertSame(2, substr_count($bytes, "\nxref\n"));
        self::assertSame(2, substr_count($bytes, 'startxref'));
        self::assertStringContainsString('/Prev', $bytes);
        self::assertStringContainsString('/Type /DocTimeStamp', $bytes);
        self::assertStringContainsString('/SubFilter /ETSI.RFC3161', $bytes);
    }

    public function testStandaloneTimestampPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('qpdf or openssl unavailable');
        }
        $doc = new Document();
        $doc->addPage();
        $doc->addDocumentTimestamp(Tsa::withClient(new TestTsa()));
        $bytes = $doc->output();

        $pdf = tempnam(sys_get_temp_dir(), 'dts');
        self::assertNotFalse($pdf);
        try {
            file_put_contents($pdf, $bytes);
            $proc = new Process([$qpdf, '--check', $pdf]);
            $proc->run();
            self::assertNotSame(2, $proc->getExitCode(),
                'qpdf reported structural errors: ' . $proc->getOutput() . $proc->getErrorOutput());
        } finally {
            @unlink($pdf);
        }
    }

    public function testCombinedSignatureStillVerifiesAfterTimestamp(): void
    {
        $openssl = (new ExecutableFinder())->find('openssl');
        if ($openssl === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $gen = TestCertificate::generate();
        $cred = SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);

        $doc = new Document();
        $page = $doc->addPage();
        $page->field(SignatureField::visible(20, 20, 80, 20, name: 'signature'));
        $doc->sign($cred, field: 'signature', reason: 'Approved',
            signedAt: new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC')));
        $doc->addDocumentTimestamp(Tsa::withClient(new TestTsa()));
        $bytes = $doc->output();

        if (preg_match('~/ByteRange \[0 (\d{10}) (\d{10}) (\d{10})\]~', $bytes, $m) !== 1) {
            self::fail('signature ByteRange not found');
        }
        $a = (int) $m[1];
        $start2 = (int) $m[2];
        $len2 = (int) $m[3];
        $signedData = substr($bytes, 0, $a) . substr($bytes, $start2, $len2);
        $hex = substr($bytes, $a + 1, ($start2 - 1) - ($a + 1));
        $der = hex2bin($hex);
        self::assertNotFalse($der);

        $derFile = tempnam(sys_get_temp_dir(), 'der');
        $contentFile = tempnam(sys_get_temp_dir(), 'cnt');
        $certFile = tempnam(sys_get_temp_dir(), 'crt');
        self::assertNotFalse($derFile);
        self::assertNotFalse($contentFile);
        self::assertNotFalse($certFile);
        try {
            file_put_contents($derFile, $der);
            file_put_contents($contentFile, $signedData);
            file_put_contents($certFile, $gen['certPem']);
            $proc = new Process([
                $openssl, 'cms', '-verify', '-binary', '-inform', 'DER',
                '-in', $derFile, '-content', $contentFile,
                '-certfile', $certFile, '-noverify',
            ]);
            $proc->run();
            self::assertSame(0, $proc->getExitCode(),
                'revision-1 signature broke after timestamping: ' . $proc->getErrorOutput());
        } finally {
            @unlink($derFile);
            @unlink($contentFile);
            @unlink($certFile);
        }
    }
}
