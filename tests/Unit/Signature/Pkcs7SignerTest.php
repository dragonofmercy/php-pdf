<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Signature\Pkcs7Signer;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class Pkcs7SignerTest extends TestCase
{
    public function testSignReturnsDerThatVerifiesAgainstSignedData(): void
    {
        $gen = TestCertificate::generate();
        $cred = SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);
        $data = 'the bytes to be signed ' . str_repeat('x', 500);

        $der = (new Pkcs7Signer())->sign($data, $cred);
        self::assertNotSame('', $der);
        // A DER SEQUENCE starts with 0x30.
        self::assertSame(0x30, ord($der[0]));

        $openssl = (new ExecutableFinder())->find('openssl');
        if ($openssl === null) {
            self::markTestSkipped('openssl CLI not on PATH');
        }
        $derFile = tempnam(sys_get_temp_dir(), 'der');
        $contentFile = tempnam(sys_get_temp_dir(), 'cnt');
        $certFile = tempnam(sys_get_temp_dir(), 'crt');
        self::assertNotFalse($derFile);
        self::assertNotFalse($contentFile);
        self::assertNotFalse($certFile);
        try {
            file_put_contents($derFile, $der);
            file_put_contents($contentFile, $data);
            file_put_contents($certFile, $gen['certPem']);
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
}
