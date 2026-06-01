<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DateTimeImmutable;
use DragonOfMercy\PhpPdf\Signature\Asn1\Der;
use DragonOfMercy\PhpPdf\Signature\Signature;
use DragonOfMercy\PhpPdf\Signature\SignatureDictionaryEmitter;
use DragonOfMercy\PhpPdf\Signature\SignaturePatcher;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Signature\TsaClient;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;

final class SignaturePatcherTimestampTest extends TestCase
{
    public function testTsaIsCarriedOnSignature(): void
    {
        $gen = TestCertificate::generate();
        $cred = SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);
        $tsa = Tsa::http('https://tsa.example/tsr');
        $sig = new Signature($cred, 'field', null, null, null, new DateTimeImmutable(), 16384, $tsa);
        self::assertSame($tsa, $sig->tsa);
    }

    public function testDefaultSignerEmbedsTokenWhenTsaPresent(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl_cms_sign unavailable');
        }
        $gen = TestCertificate::generate();
        $cred = SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);

        $token = Der::sequence(
            Der::oid('1.2.840.113549.1.7.2'),
            Der::contextConstructed(0, Der::sequence(Der::integer(3))),
        );
        $stub = new class ($token) implements TsaClient {
            public function __construct(private string $token) {}
            public function timestamp(string $messageImprint, string $hashOid): string
            {
                return $this->token;
            }
        };
        $sig = new Signature($cred, 'field', null, null, null, new DateTimeImmutable(), 16384, Tsa::withClient($stub));

        $contents = '<' . str_repeat('0', 16384 * 2) . '>';
        $buffer = "%PDF-1.7\n/ByteRange " . SignatureDictionaryEmitter::BYTERANGE_PLACEHOLDER
            . " /Contents " . $contents . "\n%%EOF";

        $patched = (new SignaturePatcher())->patch($buffer, $sig);

        if (preg_match('~/Contents <([0-9A-F]+)>~', $patched, $m) !== 1) {
            self::fail('Contents not patched');
        }
        $der = hex2bin(rtrim($m[1], '0'));
        self::assertIsString($der);
        self::assertStringContainsString(Der::oid('1.2.840.113549.1.9.16.2.14'), $der);
    }
}
