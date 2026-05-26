<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;

final class SigningCertificateTest extends TestCase
{
    public function testFromPkcs12BytesLoadsCertificateAndKey(): void
    {
        $gen = TestCertificate::generate();
        $cred = SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);
        self::assertStringContainsString('BEGIN CERTIFICATE', $cred->certificatePem);
        self::assertStringContainsString('PRIVATE KEY', $cred->privateKeyPem);
    }

    public function testWrongPasswordThrows(): void
    {
        $gen = TestCertificate::generate();
        $this->expectException(PdfException::class);
        SigningCertificate::fromPkcs12Bytes($gen['p12'], 'wrong-password');
    }

    public function testMalformedBytesThrows(): void
    {
        $this->expectException(PdfException::class);
        SigningCertificate::fromPkcs12Bytes('not a p12 bundle', 'x');
    }

    public function testFromPkcs12FileLoadsBundle(): void
    {
        $gen = TestCertificate::generate();
        $path = tempnam(sys_get_temp_dir(), 'p12');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, $gen['p12']);
            $cred = SigningCertificate::fromPkcs12($path, $gen['password']);
            self::assertStringContainsString('BEGIN CERTIFICATE', $cred->certificatePem);
        } finally {
            @unlink($path);
        }
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(PdfException::class);
        SigningCertificate::fromPkcs12(sys_get_temp_dir() . '/does-not-exist-xyz.p12', 'x');
    }
}
