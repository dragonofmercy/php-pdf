<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DateTimeImmutable;
use DateTimeZone;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\SignatureField;
use DragonOfMercy\PhpPdf\Signature\Asn1\Der;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestTsa;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SignTimestampedDocumentTest extends TestCase
{
    /** @return array{bytes: string, certPem: string} */
    private function buildTimestamped(): array
    {
        $gen = TestCertificate::generate();
        $cred = SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);

        $doc = new Document();
        $page = $doc->addPage();
        $page->field(SignatureField::visible(20, 20, 80, 20, name: 'signature'));
        $doc->sign($cred, field: 'signature', reason: 'Approved',
            signedAt: new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC')),
            timestamp: Tsa::withClient(new TestTsa()));

        return ['bytes' => $doc->output(), 'certPem' => $gen['certPem']];
    }

    public function testContentsCarriesTimeStampTokenAttribute(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl_cms_sign unavailable');
        }
        $bytes = $this->buildTimestamped()['bytes'];
        if (preg_match('~/Contents <([0-9A-F]+)>~', $bytes, $m) !== 1) {
            self::fail('Contents not patched');
        }
        // The Contents placeholder is right-padded with '0' nibbles; trim them
        // off, but keep an even length so hex2bin() never warns (the stripped
        // padding never contains the OID we look for below).
        $hex = rtrim($m[1], '0');
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }
        $der = hex2bin($hex);
        self::assertIsString($der);
        $oid = Der::oid('1.2.840.113549.1.9.16.2.14');
        self::assertSame(1, substr_count($der, $oid), 'expected exactly one timeStampToken attribute');
    }

    public function testBaseSignatureStillVerifiesAfterTimestamping(): void
    {
        $openssl = (new ExecutableFinder())->find('openssl');
        if ($openssl === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $built = $this->buildTimestamped();
        $bytes = $built['bytes'];

        if (preg_match('~/ByteRange \[0 (\d{10}) (\d{10}) (\d{10})\]~', $bytes, $m) !== 1) {
            self::fail('ByteRange not found');
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
            file_put_contents($certFile, $built['certPem']);
            $proc = new Process([
                $openssl, 'cms', '-verify', '-binary', '-inform', 'DER',
                '-in', $derFile, '-content', $contentFile,
                '-certfile', $certFile, '-noverify',
            ]);
            $proc->run();
            self::assertSame(0, $proc->getExitCode(),
                'CMS verify failed after timestamping: ' . $proc->getErrorOutput());
        } finally {
            @unlink($derFile);
            @unlink($contentFile);
            @unlink($certFile);
        }
    }

    public function testQpdfCheckHasNoStructuralErrors(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('qpdf or openssl unavailable');
        }
        $bytes = $this->buildTimestamped()['bytes'];
        $pdf = tempnam(sys_get_temp_dir(), 'tsdoc');
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
}
