<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Support;

use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

final class TestPkiTsaTest extends TestCase
{
    public function testIssueTsaWithCrlProducesArtifacts(): void
    {
        $pki = TestPki::issueTsaWithCrl();
        if ($pki === null) {
            self::markTestSkipped('openssl CLI unavailable');
        }
        self::assertStringContainsString('BEGIN CERTIFICATE', $pki['rootPem']);
        self::assertStringContainsString('BEGIN CERTIFICATE', $pki['signerPem']);
        self::assertStringContainsString('BEGIN CERTIFICATE', $pki['tsaPem']);
        self::assertStringContainsString('PRIVATE KEY', $pki['tsaKeyPem']);
        self::assertNotSame('', $pki['signerP12']);
        self::assertSame(0x30, ord($pki['crlDer'][0]));
        self::assertFileExists($pki['tsaConfigPath']);
        $eku = (string) shell_exec('openssl x509 -in ' . escapeshellarg($pki['dir'] . '/tsa.pem') . ' -noout -ext extendedKeyUsage 2>&1');
        self::assertStringContainsString('Time Stamping', $eku);
    }
}
