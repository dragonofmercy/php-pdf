<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Ltv;

use DragonOfMercy\PhpPdf\Signature\Ltv\CertificateChain;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

final class CertificateChainTest extends TestCase
{
    public function testPemToDerRoundTrips(): void
    {
        $gen = TestCertificate::generate();
        $der = CertificateChain::pemToDer($gen['certPem']);
        self::assertNotSame('', $der);
        $reArmored = "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE-----\n";
        $parsed = openssl_x509_parse($reArmored);
        self::assertIsArray($parsed);
    }

    public function testChainPemFromCredentialPutsSignerFirst(): void
    {
        $gen = TestCertificate::generate();
        $cred = SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);
        $chain = CertificateChain::chainPem($cred);
        self::assertNotEmpty($chain);
        self::assertStringContainsString('BEGIN CERTIFICATE', $chain[0]);
    }

    public function testCrlUrlsParsesUriTokens(): void
    {
        $urls = CertificateChain::crlUrlsFromExtensionText("Full Name:\n  URI:http://crl.test/leaf.crl\n");
        self::assertSame(['http://crl.test/leaf.crl'], $urls);
    }

    public function testCrlUrlsEmptyWhenNoCdp(): void
    {
        self::assertSame([], CertificateChain::crlUrlsFromExtensionText(''));
    }

    public function testCrlUrlsStopAtParenAndComma(): void
    {
        $urls = CertificateChain::crlUrlsFromExtensionText("URI:http://crl.test/a.crl (reason)\nURI:http://crl.test/b.crl,\n");
        self::assertSame(['http://crl.test/a.crl', 'http://crl.test/b.crl'], $urls);
    }

    public function testOcspUrlsReadsAiaResponder(): void
    {
        $pki = TestPki::issueWithOcsp();
        if ($pki === null) {
            self::markTestSkipped('openssl CLI unavailable');
        }
        $urls = CertificateChain::ocspUrls($pki['leafPem']);
        self::assertSame(['http://ocsp.example.com/'], $urls);
    }

    public function testOcspUrlsEmptyWhenNoAia(): void
    {
        $pki = TestPki::issueWithCrl();
        if ($pki === null) {
            self::markTestSkipped('openssl CLI unavailable');
        }
        self::assertSame([], CertificateChain::ocspUrls($pki['leafPem']));
    }

    public function testOcspUrlsFromExtensionTextParsesOcspLine(): void
    {
        $text = "CA Issuers - URI:http://ca.example.com/ca.crt\nOCSP - URI:http://ocsp.example.com/\n";
        self::assertSame(['http://ocsp.example.com/'], CertificateChain::ocspUrlsFromExtensionText($text));
    }
}
