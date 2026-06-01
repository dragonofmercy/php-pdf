<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Asn1;

use DragonOfMercy\PhpPdf\Signature\Asn1\Der;
use DragonOfMercy\PhpPdf\Signature\Asn1\TimeStampReqBuilder;
use DragonOfMercy\PhpPdf\Signature\TsaHashAlgorithm;
use PHPUnit\Framework\TestCase;

final class TimeStampReqBuilderTest extends TestCase
{
    public function testBuildsParseableRequestWithExpectedFields(): void
    {
        $imprint = hash('sha256', 'hello', true);
        $nonce = "\x12\x34\x56\x78";
        $der = TimeStampReqBuilder::build($imprint, TsaHashAlgorithm::SHA256->oid(), $nonce);

        // Outer SEQUENCE.
        $outer = Der::readHeader($der, 0);
        self::assertSame(0x30, $outer['tag']);
        self::assertSame(strlen($der), $outer['end']);

        // First child: version INTEGER 1.
        $version = Der::readHeader($der, $outer['valueStart']);
        self::assertSame(0x02, $version['tag']);
        self::assertSame("\x01", substr($der, $version['valueStart'], $version['length']));

        // Second child: messageImprint SEQUENCE.
        $mi = Der::readHeader($der, $version['end']);
        self::assertSame(0x30, $mi['tag']);

        // messageImprint -> hashAlgorithm SEQUENCE then hashedMessage OCTET STRING.
        $alg = Der::readHeader($der, $mi['valueStart']);
        self::assertSame(0x30, $alg['tag']);
        $hashed = Der::readHeader($der, $alg['end']);
        self::assertSame(0x04, $hashed['tag']);
        self::assertSame($imprint, substr($der, $hashed['valueStart'], $hashed['length']));

        // The DER must contain the certReq BOOLEAN TRUE.
        self::assertStringContainsString("\x01\x01\xFF", $der);
    }

    public function testNonceIsEncodedAsPositiveInteger(): void
    {
        // A nonce with the high bit set must gain a leading 0x00.
        $der = TimeStampReqBuilder::build(hash('sha256', 'x', true), TsaHashAlgorithm::SHA256->oid(), "\x80\x01");
        self::assertStringContainsString("\x02\x03\x00\x80\x01", $der);
    }
}
