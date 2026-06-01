<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\HttpTsaClient;
use DragonOfMercy\PhpPdf\Signature\TsaHashAlgorithm;
use PHPUnit\Framework\TestCase;

final class HttpTsaClientTest extends TestCase
{
    public function testRejectsNonHttpUrl(): void
    {
        $client = new HttpTsaClient('ftp://example.invalid/tsr', null);
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('~http~i');
        $client->timestamp(hash('sha256', 'x', true), TsaHashAlgorithm::SHA256->oid());
    }
}
