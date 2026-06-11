<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestTsa;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use PHPUnit\Framework\TestCase;

final class IncrementalRevisionStackerTest extends TestCase
{
    public function testDocumentSignThenTimestampProducesTwoByteRanges(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $gen = TestCertificate::generate();
        $cred = SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);
        $doc = new Document();
        $doc->addPage();
        $doc->addSignature($cred);
        $doc->addDocumentTimestamp(Tsa::withClient(new TestTsa()));
        $bytes = $doc->output();
        self::assertSame(2, substr_count($bytes, '/ByteRange'));
        self::assertStringContainsString('/DocTimeStamp', $bytes);
    }
}
