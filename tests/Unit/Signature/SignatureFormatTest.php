<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Signature\SignatureFormat;
use PHPUnit\Framework\TestCase;

final class SignatureFormatTest extends TestCase
{
    public function testSubFilterNames(): void
    {
        self::assertSame('adbe.pkcs7.detached', SignatureFormat::Pkcs7Detached->subFilter());
        self::assertSame('ETSI.CAdES.detached', SignatureFormat::EtsiCadesDetached->subFilter());
    }
}
