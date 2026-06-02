<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Ltv;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\Ltv\HttpOcspClient;
use PHPUnit\Framework\TestCase;

final class HttpOcspClientTest extends TestCase
{
    public function testRejectsNonHttpUrl(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('OCSP URL must be http(s)');
        (new HttpOcspClient())->request('ftp://ocsp.example.com/', "\x30\x00");
    }
}
