<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\DocumentTimestamp;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Signature\TsaClient;
use PHPUnit\Framework\TestCase;

final class DocumentTimestampTest extends TestCase
{
    private function tsa(): Tsa
    {
        $stub = new class implements TsaClient {
            public function timestamp(string $messageImprint, string $hashOid): string
            {
                return 'tok';
            }
        };
        return Tsa::withClient($stub);
    }

    public function testHoldsTsaAndDefaultMaxBytes(): void
    {
        $dt = new DocumentTimestamp($this->tsa());
        self::assertSame(16384, $dt->maxSignatureBytes);
    }

    public function testRejectsNonPositiveMaxBytes(): void
    {
        $this->expectException(PdfException::class);
        new DocumentTimestamp($this->tsa(), 0);
    }
}
