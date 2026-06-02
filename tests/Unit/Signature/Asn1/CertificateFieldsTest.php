<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Asn1;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\Asn1\CertificateFields;
use DragonOfMercy\PhpPdf\Signature\Ltv\CertificateChain;
use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class CertificateFieldsTest extends TestCase
{
    public function testParsesSerialNameAndKey(): void
    {
        $pki = TestPki::issueWithOcsp();
        if ($pki === null) {
            self::markTestSkipped('openssl CLI unavailable');
        }
        $der = CertificateChain::pemToDer($pki['leafPem']);
        $fields = CertificateFields::fromDer($der);

        self::assertSame(0x30, ord($fields->subjectNameDer()[0]));
        self::assertNotSame('', $fields->subjectPublicKeyBytes());

        $openssl = (new ExecutableFinder())->find('openssl');
        self::assertNotNull($openssl);
        $leaf = (string) tempnam(sys_get_temp_dir(), 'leaf');
        file_put_contents($leaf, $pki['leafPem']);
        try {
            $p = new Process([$openssl, 'x509', '-in', $leaf, '-noout', '-serial']);
            $p->run();
            $serialHex = strtolower(trim(str_replace('serial=', '', $p->getOutput())));
        } finally {
            @unlink($leaf);
        }
        self::assertSame($serialHex, strtolower(bin2hex($fields->serialNumber())));
    }

    public function testIssuerNameDerMatchesOpenssl(): void
    {
        $pki = TestPki::issueWithOcsp();
        $openssl = (new ExecutableFinder())->find('openssl');
        if ($pki === null || $openssl === null) {
            self::markTestSkipped('openssl CLI unavailable');
        }
        $der = CertificateChain::pemToDer($pki['leafPem']);
        $issuerNameDer = CertificateFields::fromDer($der)->issuerNameDer();

        self::assertSame(0x30, ord($issuerNameDer[0]));
        $leaf = (string) tempnam(sys_get_temp_dir(), 'leaf');
        file_put_contents($leaf, $pki['leafPem']);
        try {
            $p = new Process([$openssl, 'x509', '-in', $leaf, '-noout', '-issuer']);
            $p->run();
            self::assertStringContainsString('phppdf test root', $p->getOutput());
        } finally {
            @unlink($leaf);
        }
        self::assertStringContainsString('phppdf test root', $issuerNameDer);
    }

    public function testThrowsOnNonCertificate(): void
    {
        $this->expectException(PdfException::class);
        CertificateFields::fromDer("\x30\x03\x02\x01\x01");
    }
}
