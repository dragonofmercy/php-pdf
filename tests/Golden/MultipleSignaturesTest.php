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

final class MultipleSignaturesTest extends TestCase
{
    /**
     * Verifies the i-th detached signature in document order against its byte
     * range. $certPems are the signer certs in the same order as the /ByteRange
     * entries appear in the file.
     *
     * @param list<string> $certPems
     */
    private function assertAllSignaturesVerify(string $openssl, string $bytes, array $certPems): void
    {
        if (preg_match_all('~/ByteRange \[0 (\d{10}) (\d{10}) (\d{10})\]~', $bytes, $all, PREG_SET_ORDER) === false) {
            self::fail('ByteRange scan failed');
        }
        self::assertCount(count($certPems), $all, 'one ByteRange per signature');
        foreach ($all as $i => $m) {
            $a = (int) $m[1];
            $start2 = (int) $m[2];
            $len2 = (int) $m[3];
            $signedData = substr($bytes, 0, $a) . substr($bytes, $start2, $len2);
            $hex = substr($bytes, $a + 1, ($start2 - 1) - ($a + 1));
            $der = hex2bin($hex);
            self::assertNotFalse($der, "signature {$i} hex");

            $derFile = tempnam(sys_get_temp_dir(), 'der');
            $contentFile = tempnam(sys_get_temp_dir(), 'cnt');
            $certFile = tempnam(sys_get_temp_dir(), 'crt');
            self::assertNotFalse($derFile);
            self::assertNotFalse($contentFile);
            self::assertNotFalse($certFile);
            try {
                file_put_contents($derFile, $der);
                file_put_contents($contentFile, $signedData);
                file_put_contents($certFile, $certPems[$i]);
                $proc = new Process([
                    $openssl, 'cms', '-verify', '-binary', '-inform', 'DER',
                    '-in', $derFile, '-content', $contentFile,
                    '-certfile', $certFile, '-noverify',
                ]);
                $proc->run();
                self::assertSame(0, $proc->getExitCode(),
                    "signature {$i} did not verify: " . $proc->getErrorOutput());
            } finally {
                @unlink($derFile);
                @unlink($contentFile);
                @unlink($certFile);
            }
        }
    }

    public function testTwoSignaturesBothVerify(): void
    {
        $openssl = (new ExecutableFinder())->find('openssl');
        if ($openssl === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $genA = TestCertificate::generate();
        $genB = TestCertificate::generate();
        $credA = SigningCertificate::fromPkcs12Bytes($genA['p12'], $genA['password']);
        $credB = SigningCertificate::fromPkcs12Bytes($genB['p12'], $genB['password']);

        $doc = new Document();
        $page = $doc->addPage();
        $page->field(SignatureField::visible(20, 20, 80, 20, name: 'sigA'));
        $doc->sign($credA, field: 'sigA', reason: 'Authored',
            signedAt: new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC')));
        $doc->addSignature($credB, reason: 'Reviewed');
        $bytes = $doc->output();

        $this->assertAllSignaturesVerify($openssl, $bytes, [$genA['certPem'], $genB['certPem']]);
    }

    public function testThreeSignersNoBaseSignature(): void
    {
        $openssl = (new ExecutableFinder())->find('openssl');
        if ($openssl === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $gens = [TestCertificate::generate(), TestCertificate::generate()];
        $doc = new Document();
        $doc->addPage();
        foreach ($gens as $g) {
            $doc->addSignature(SigningCertificate::fromPkcs12Bytes($g['p12'], $g['password']));
        }
        $bytes = $doc->output();
        $this->assertAllSignaturesVerify($openssl, $bytes, [$gens[0]['certPem'], $gens[1]['certPem']]);
    }

    public function testSignThenSignThenTimestampQpdfClean(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null || !function_exists('openssl_cms_sign')) {
            self::markTestSkipped('qpdf or openssl unavailable');
        }
        $genA = TestCertificate::generate();
        $credA = SigningCertificate::fromPkcs12Bytes($genA['p12'], $genA['password']);
        $genB = TestCertificate::generate();
        $credB = SigningCertificate::fromPkcs12Bytes($genB['p12'], $genB['password']);

        $doc = new Document();
        $page = $doc->addPage();
        $page->field(SignatureField::visible(20, 20, 80, 20, name: 'sigA'));
        $doc->sign($credA, field: 'sigA');
        $doc->addSignature($credB);
        $doc->addDocumentTimestamp(Tsa::withClient(new TestTsa()));
        $bytes = $doc->output();

        self::assertSame(3, substr_count($bytes, '/ByteRange'));
        self::assertStringContainsString('/DocTimeStamp', $bytes);

        $pdf = tempnam(sys_get_temp_dir(), 'multisig');
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
