<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\SignatureField;
use DragonOfMercy\PhpPdf\Form\TextField;
use DragonOfMercy\PhpPdf\Pdf;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;

final class PdfSignExistingFieldReuseTest extends TestCase
{
    private function sourceWithEmptySigField(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(SignatureField::visible(20, 20, 80, 20, name: 'CounterSign'));
        return $doc->output();
    }

    private function cred(): SigningCertificate
    {
        $gen = TestCertificate::generate();
        return SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);
    }

    public function testReuseDoesNotDuplicateField(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $src = $this->sourceWithEmptySigField();
        // The source already defines the empty field exactly once.
        self::assertSame(1, substr_count($src, '(CounterSign)'));

        $pdf = Pdf::fromBytes($src);
        $pdf->sign($this->cred(), field: 'CounterSign');
        $bytes = $pdf->output();

        // Reuse re-emits the SAME field object (object number unchanged) with /V
        // added, rather than creating a second field. Because an incremental
        // update supersedes - but does not erase - the original object bytes,
        // the literal name physically appears twice (one dead, one live), so we
        // assert "+1, not a duplicate field": exactly one re-emitted occurrence
        // on top of the source, a single signature value, and one signature.
        self::assertSame(2, substr_count($bytes, '(CounterSign)'));
        self::assertSame(1, substr_count($bytes, '/V '));
        self::assertSame(1, substr_count($bytes, '/ByteRange'));
    }

    public function testSigningNonSignatureFieldThrows(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new TextField(20, 20, 80, 12, name: 'Name'));
        $pdf = Pdf::fromBytes($doc->output());
        // Validation lives at output() (all field resolution in one place), so
        // the exception surfaces when the revision is actually built.
        $pdf->sign($this->cred(), field: 'Name');
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('~Name.*not a signature field~');
        $pdf->output();
    }
}
