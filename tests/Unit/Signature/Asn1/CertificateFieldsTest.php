<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Asn1;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\Asn1\CertificateFields;
use DragonOfMercy\PhpPdf\Signature\Ltv\CertificateChain;
use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

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

        // Cross-check the parsed serial against PHP's openssl binding, which parses
        // independently of our hand-rolled ASN.1. The binding is deterministic and
        // needs no subprocess. It reports the serial magnitude in hex; our
        // serialNumber() returns the raw DER INTEGER content octets, which carry a
        // leading 0x00 sign byte whenever the high bit is set (so the integer stays
        // positive). Strip that sign byte before comparing the two representations.
        $parsed = openssl_x509_parse($pki['leafPem']);
        self::assertIsArray($parsed);
        self::assertArrayHasKey('serialNumberHex', $parsed);
        $expectedHex = $parsed['serialNumberHex'];
        self::assertIsString($expectedHex);

        $serialMagnitude = $fields->serialNumber();
        if (strlen($serialMagnitude) > 1 && $serialMagnitude[0] === "\x00") {
            $serialMagnitude = substr($serialMagnitude, 1);
        }
        self::assertSame(strtolower($expectedHex), strtolower(bin2hex($serialMagnitude)));
    }

    public function testIssuerNameDerMatchesOpenssl(): void
    {
        $pki = TestPki::issueWithOcsp();
        if ($pki === null) {
            self::markTestSkipped('openssl CLI unavailable');
        }
        $der = CertificateChain::pemToDer($pki['leafPem']);
        $issuerNameDer = CertificateFields::fromDer($der)->issuerNameDer();

        self::assertSame(0x30, ord($issuerNameDer[0]));
        self::assertStringContainsString('phppdf test root', $issuerNameDer);

        // Cross-check against PHP's openssl binding (deterministic, no subprocess).
        $parsed = openssl_x509_parse($pki['leafPem']);
        self::assertIsArray($parsed);
        $issuer = $parsed['issuer'] ?? null;
        self::assertIsArray($issuer);
        self::assertSame('phppdf test root', $issuer['CN'] ?? null);
    }

    public function testThrowsOnNonCertificate(): void
    {
        $this->expectException(PdfException::class);
        CertificateFields::fromDer("\x30\x03\x02\x01\x01");
    }
}
