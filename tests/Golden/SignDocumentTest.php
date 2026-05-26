<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DateTimeImmutable;
use DateTimeZone;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\SignatureField;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SignDocumentTest extends TestCase
{
    /**
     * @return array{bytes: string, certPem: string}
     */
    private function buildSigned(int $maxBytes = 16384): array
    {
        $gen = TestCertificate::generate();
        $cred = SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);

        $doc = new Document();
        $page = $doc->addPage();
        $page->field(SignatureField::visible(20, 20, 80, 20, name: 'signature'));
        $doc->sign($cred, field: 'signature', reason: 'Approved', location: 'Geneva',
            signedAt: new DateTimeImmutable('2026-05-26 12:00:00', new DateTimeZone('UTC')),
            maxSignatureBytes: $maxBytes);

        return ['bytes' => $doc->output(), 'certPem' => $gen['certPem']];
    }

    public function testSignedDocumentHasWellFormedByteRangeAndContents(): void
    {
        $bytes = $this->buildSigned()['bytes'];
        self::assertStringContainsString('/Type /Sig', $bytes);
        self::assertStringContainsString('/SubFilter /adbe.pkcs7.detached', $bytes);

        if (preg_match('~/ByteRange \[0 (\d{10}) (\d{10}) (\d{10})\]~', $bytes, $m) !== 1) {
            self::fail('ByteRange not found / not patched');
        }
        $a = (int) $m[1];
        $b = (int) $m[2];
        $c = (int) $m[3];
        self::assertGreaterThan(0, $a);
        self::assertGreaterThan($a, $b);
        self::assertSame(strlen($bytes) - $b, $c, 'third slot = remaining length after the gap');

        // a is the offset of '<', b-1 is the offset of '>'.
        self::assertSame('<', $bytes[$a]);
        self::assertSame('>', $bytes[$b - 1]);
        $hex = substr($bytes, $a + 1, ($b - 1) - ($a + 1));
        self::assertSame(16384 * 2, strlen($hex), 'default placeholder is 16384 bytes');
        self::assertMatchesRegularExpression('~^[0-9A-F]+$~', $hex);
    }

    public function testSignedDocumentEmbedsSignerMetadata(): void
    {
        $bytes = $this->buildSigned()['bytes'];
        self::assertStringContainsString('/M (D:20260526120000Z)', $bytes);
        self::assertStringContainsString('/Reason (Approved)', $bytes);
        self::assertStringContainsString('/Location (Geneva)', $bytes);
    }

    public function testCmsSignatureVerifiesOverByteRanges(): void
    {
        $openssl = (new ExecutableFinder())->find('openssl');
        if ($openssl === null) {
            self::markTestSkipped('openssl CLI not on PATH');
        }
        $built = $this->buildSigned();
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
            self::assertSame(0, $proc->getExitCode(), 'CMS verify failed: ' . $proc->getErrorOutput());
        } finally {
            @unlink($derFile);
            @unlink($contentFile);
            @unlink($certFile);
        }
    }

    public function testQpdfCheckHasNoStructuralErrors(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        $bytes = $this->buildSigned()['bytes'];
        $pdf = tempnam(sys_get_temp_dir(), 'signed');
        self::assertNotFalse($pdf);
        try {
            file_put_contents($pdf, $bytes);
            $proc = new Process([$qpdf, '--check', $pdf]);
            $proc->run();
            // 0 = ok, 3 = warnings (acceptable for a signature), 2 = errors (fail).
            self::assertNotSame(2, $proc->getExitCode(),
                'qpdf reported structural errors: ' . $proc->getOutput() . $proc->getErrorOutput());
        } finally {
            @unlink($pdf);
        }
    }

    public function testSignatureLargerThanPlaceholderThrows(): void
    {
        // 64-byte placeholder is far too small for a real CMS signature.
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $this->buildSigned(64);
    }
}
