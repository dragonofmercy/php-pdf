<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Asn1;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\Asn1\Der;
use DragonOfMercy\PhpPdf\Signature\Asn1\TimeStampRespParser;
use PHPUnit\Framework\TestCase;

final class TimeStampRespParserTest extends TestCase
{
    /** Builds a ContentInfo whose contentType is id-signedData (content body is opaque here). */
    private function fakeToken(): string
    {
        return Der::sequence(
            Der::oid('1.2.840.113549.1.7.2'),
            Der::contextConstructed(0, Der::sequence(Der::integer(3))),
        );
    }

    private function resp(int $status, ?string $token): string
    {
        $statusInfo = Der::sequence(Der::integer($status));
        $parts = [$statusInfo];
        if ($token !== null) {
            $parts[] = $token;
        }
        return Der::sequence(...$parts);
    }

    public function testExtractsTokenOnGranted(): void
    {
        $token = $this->fakeToken();
        $extracted = TimeStampRespParser::extractToken($this->resp(0, $token));
        self::assertSame($token, $extracted);
    }

    public function testExtractsTokenOnGrantedWithMods(): void
    {
        $token = $this->fakeToken();
        self::assertSame($token, TimeStampRespParser::extractToken($this->resp(1, $token)));
    }

    public function testRejectsNonGrantedStatus(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('~status 2~');
        TimeStampRespParser::extractToken($this->resp(2, $this->fakeToken()));
    }

    public function testRejectsMissingToken(): void
    {
        $this->expectException(PdfException::class);
        TimeStampRespParser::extractToken($this->resp(0, null));
    }

    public function testRejectsTokenThatIsNotSignedData(): void
    {
        $notSignedData = Der::sequence(
            Der::oid('1.2.840.113549.1.7.1'), // id-data, wrong
            Der::contextConstructed(0, Der::octetString('x')),
        );
        $this->expectException(PdfException::class);
        TimeStampRespParser::extractToken($this->resp(0, $notSignedData));
    }
}
