<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Asn1;

use DragonOfMercy\PhpPdf\Signature\Asn1\Der;
use DragonOfMercy\PhpPdf\Signature\Asn1\OcspRequestBuilder;
use DragonOfMercy\PhpPdf\Signature\Ltv\CertificateChain;
use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class OcspRequestBuilderTest extends TestCase
{
    public function testMatchesOpensslReferenceRequest(): void
    {
        $pki = TestPki::issueWithOcsp();
        $openssl = (new ExecutableFinder())->find('openssl');
        if ($pki === null || $openssl === null) {
            self::markTestSkipped('openssl CLI unavailable');
        }
        $leafDer = CertificateChain::pemToDer($pki['leafPem']);
        $rootDer = CertificateChain::pemToDer($pki['rootPem']);

        $ours = OcspRequestBuilder::build($leafDer, $rootDer);

        $reqPath = (string) tempnam(sys_get_temp_dir(), 'req');
        try {
            $p = new Process([$openssl, 'ocsp',
                '-issuer', $pki['dir'] . '/root.pem',
                '-sha1', '-cert', $pki['dir'] . '/leaf.pem',
                '-reqout', $reqPath, '-no_nonce']);
            $p->run();
            self::assertSame(0, $p->getExitCode(), $p->getErrorOutput());
            $reference = (string) file_get_contents($reqPath);
        } finally {
            @unlink($reqPath);
        }

        self::assertSame(bin2hex($reference), bin2hex($ours));
    }

    public function testProducesParseableCertId(): void
    {
        $pki = TestPki::issueWithOcsp();
        if ($pki === null) {
            self::markTestSkipped('openssl CLI unavailable');
        }
        $leafDer = CertificateChain::pemToDer($pki['leafPem']);
        $rootDer = CertificateChain::pemToDer($pki['rootPem']);
        $request = OcspRequestBuilder::build($leafDer, $rootDer);

        self::assertSame(0x30, ord($request[0]));
        $outer = Der::readHeader($request, 0);
        self::assertSame(strlen($request), $outer['end']);
    }
}
