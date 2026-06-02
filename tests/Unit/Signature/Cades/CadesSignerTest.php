<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Cades;

use DragonOfMercy\PhpPdf\Signature\Asn1\Der;
use DragonOfMercy\PhpPdf\Signature\Cades\CadesSigner;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class CadesSignerTest extends TestCase
{
    public function testProducesVerifiableDetachedCms(): void
    {
        $pki = TestPki::issueWithOcsp();
        $openssl = (new ExecutableFinder())->find('openssl');
        if ($pki === null || $openssl === null) {
            self::markTestSkipped('openssl CLI unavailable');
        }
        $cred = SigningCertificate::fromPkcs12Bytes($pki['leafP12'], $pki['password']);
        $content = 'detached content for CAdES signing';

        $cms = (new CadesSigner())->sign($content, $cred);

        $outer = Der::readHeader($cms, 0);
        self::assertSame(0x30, $outer['tag']);
        self::assertSame(strlen($cms), $outer['end']);

        $cmsF = (string) tempnam(sys_get_temp_dir(), 'cms');
        $cntF = (string) tempnam(sys_get_temp_dir(), 'cnt');
        $caF = (string) tempnam(sys_get_temp_dir(), 'ca');
        try {
            file_put_contents($cmsF, $cms);
            file_put_contents($cntF, $content);
            file_put_contents($caF, $pki['rootPem']);
            $p = new Process([$openssl, 'cms', '-verify', '-binary', '-inform', 'DER',
                '-in', $cmsF, '-content', $cntF, '-CAfile', $caF]);
            $p->run();
            self::assertSame(0, $p->getExitCode(), 'cms verify failed: ' . $p->getErrorOutput());
        } finally {
            @unlink($cmsF);
            @unlink($cntF);
            @unlink($caF);
        }

        $signingCertV2OidDer = hex2bin('060b2a864886f70d010910022f');
        self::assertIsString($signingCertV2OidDer);
        self::assertStringContainsString($signingCertV2OidDer, $cms);
    }
}
