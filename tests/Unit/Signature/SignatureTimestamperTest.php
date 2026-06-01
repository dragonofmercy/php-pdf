<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Signature\Asn1\Der;
use DragonOfMercy\PhpPdf\Signature\SignatureTimestamper;
use DragonOfMercy\PhpPdf\Signature\TsaClient;
use DragonOfMercy\PhpPdf\Signature\TsaHashAlgorithm;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;

final class SignatureTimestamperTest extends TestCase
{
    private function realCms(string $data): string
    {
        $gen = TestCertificate::generate();
        $in = tempnam(sys_get_temp_dir(), 'tsd_in');
        $out = tempnam(sys_get_temp_dir(), 'tsd_out');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        try {
            file_put_contents($in, $data);
            $cert = openssl_x509_read($gen['certPem']);
            $key = openssl_pkey_get_private($gen['keyPem']);
            self::assertNotFalse($cert);
            self::assertNotFalse($key);
            $ok = openssl_cms_sign($in, $out, $cert, $key, [],
                OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
            self::assertTrue($ok);
            $der = file_get_contents($out);
            self::assertIsString($der);
            return $der;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /** A token shaped as a CMS ContentInfo/SignedData so the structure is well-formed. */
    private function fakeToken(): string
    {
        return Der::sequence(
            Der::oid('1.2.840.113549.1.7.2'),
            Der::contextConstructed(0, Der::sequence(Der::integer(3))),
        );
    }

    public function testAppendsTimeStampTokenUnsignedAttribute(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl_cms_sign unavailable');
        }
        $cms = $this->realCms('hello world');
        $token = $this->fakeToken();
        $stub = new class ($token) implements TsaClient {
            public function __construct(private string $token) {}
            public function timestamp(string $messageImprint, string $hashOid): string
            {
                return $this->token;
            }
        };

        $result = (new SignatureTimestamper(TsaHashAlgorithm::SHA256))->timestamp($cms, $stub);

        // id-aa-timeStampToken OID DER must now appear in the CMS.
        $oidDer = Der::oid('1.2.840.113549.1.9.16.2.14');
        self::assertStringContainsString($oidDer, $result);
        // The token bytes must be embedded verbatim.
        self::assertStringContainsString($token, $result);
        // The result must still be a well-formed outer ContentInfo SEQUENCE
        // whose declared length matches the buffer.
        $outer = Der::readHeader($result, 0);
        self::assertSame(0x30, $outer['tag']);
        self::assertSame(strlen($result), $outer['end']);
        // It must have grown by at least the token length.
        self::assertGreaterThan(strlen($cms), strlen($result));
    }

    public function testIdempotentStructureForChainOfHeaders(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl_cms_sign unavailable');
        }
        $cms = $this->realCms('payload');
        $token = $this->fakeToken();
        $stub = new class ($token) implements TsaClient {
            public function __construct(private string $token) {}
            public function timestamp(string $messageImprint, string $hashOid): string
            {
                return $this->token;
            }
        };
        $result = (new SignatureTimestamper(TsaHashAlgorithm::SHA256))->timestamp($cms, $stub);

        // Walk ContentInfo -> [0] -> SignedData and confirm every nested header
        // declares a length that lands exactly on its parent boundary.
        $outer = Der::readHeader($result, 0);
        $oid = Der::readHeader($result, $outer['valueStart']);
        self::assertSame(0x06, $oid['tag']);
        $explicit = Der::readHeader($result, $oid['end']);
        self::assertSame(0xA0, $explicit['tag']);
        self::assertSame($outer['end'], $explicit['end']);
        $signedData = Der::readHeader($result, $explicit['valueStart']);
        self::assertSame(0x30, $signedData['tag']);
        self::assertSame($explicit['end'], $signedData['end']);
    }
}
