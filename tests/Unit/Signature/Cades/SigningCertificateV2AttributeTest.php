<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Cades;

use DragonOfMercy\PhpPdf\Signature\Asn1\Der;
use DragonOfMercy\PhpPdf\Signature\Cades\SigningCertificateV2Attribute;
use DragonOfMercy\PhpPdf\Signature\Ltv\CertificateChain;
use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

final class SigningCertificateV2AttributeTest extends TestCase
{
    public function testValueCarriesCertHashAndIssuerSerial(): void
    {
        $pki = TestPki::issueWithOcsp();
        if ($pki === null) {
            self::markTestSkipped('openssl CLI unavailable');
        }
        $certDer = CertificateChain::pemToDer($pki['leafPem']);

        $value = SigningCertificateV2Attribute::build($certDer);

        $outer = Der::readHeader($value, 0);
        self::assertSame(0x30, $outer['tag']);
        self::assertSame(strlen($value), $outer['end']);

        $certs = Der::readHeader($value, $outer['valueStart']);
        self::assertSame(0x30, $certs['tag']);
        $ess = Der::readHeader($value, $certs['valueStart']);
        self::assertSame(0x30, $ess['tag']);

        $certHash = Der::readHeader($value, $ess['valueStart']);
        self::assertSame(0x04, $certHash['tag']);
        self::assertSame(
            hash('sha256', $certDer, true),
            substr($value, $certHash['valueStart'], $certHash['length']),
        );
    }
}
