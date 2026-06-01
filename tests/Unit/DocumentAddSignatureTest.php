<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\SignatureField;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;

final class DocumentAddSignatureTest extends TestCase
{
    private function cred(): SigningCertificate
    {
        $gen = TestCertificate::generate();
        return SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);
    }

    public function testAddSignatureIsFluent(): void
    {
        if (!function_exists('openssl_pkcs12_read')) {
            self::markTestSkipped('openssl unavailable');
        }
        $doc = new Document();
        self::assertSame($doc, $doc->addSignature($this->cred(), reason: 'Reviewed'));
    }

    public function testAppendedSignatureFieldNameCollisionThrows(): void
    {
        if (!function_exists('openssl_pkcs12_read')) {
            self::markTestSkipped('openssl unavailable');
        }
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(SignatureField::visible(10, 10, 50, 20, name: 'Signature1'));
        $doc->addSignature($this->cred());
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('~Signature1~');
        $doc->output();
    }
}
