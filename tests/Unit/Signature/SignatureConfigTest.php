<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DateTimeImmutable;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\Signature;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;

final class SignatureConfigTest extends TestCase
{
    private function cred(): SigningCertificate
    {
        $gen = TestCertificate::generate();
        return SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);
    }

    public function testHoldsFieldAndMetadata(): void
    {
        $when = new DateTimeImmutable('2026-05-26 10:00:00');
        $sig = new Signature($this->cred(), 'sig', 'reason', 'loc', 'me@x.com', $when, 16384);
        self::assertSame('sig', $sig->fieldName);
        self::assertSame('reason', $sig->reason);
        self::assertSame(16384, $sig->maxSignatureBytes);
        self::assertSame($when, $sig->signedAt);
    }

    public function testEmptyFieldNameThrows(): void
    {
        $this->expectException(PdfException::class);
        new Signature($this->cred(), '', null, null, null, new DateTimeImmutable(), 16384);
    }

    public function testNonPositiveMaxBytesThrows(): void
    {
        $this->expectException(PdfException::class);
        new Signature($this->cred(), 'sig', null, null, null, new DateTimeImmutable(), 0);
    }

    public function testDocumentSignReturnsSelfAndDoesNotThrow(): void
    {
        $doc = new Document();
        $doc->addPage();
        $result = $doc->sign($this->cred(), field: 'sig', reason: 'r');
        self::assertSame($doc, $result);
    }
}
