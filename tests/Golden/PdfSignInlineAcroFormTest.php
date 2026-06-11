<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\PdfEditor;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;

final class PdfSignInlineAcroFormTest extends TestCase
{
    /**
     * A minimal valid PDF whose catalog has a DIRECT (inline) /AcroForm dict.
     */
    private function inlineAcroFormPdf(): string
    {
        $objs = [];
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields [] /SigFlags 0 >> >>";
        $objs[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objs[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>";

        $pdf = "%PDF-1.7\n";
        $offsets = [];
        foreach ($objs as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "{$n} 0 obj\n{$body}\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 4\n";
        $pdf .= "0000000000 65535 f \n";
        for ($n = 1; $n <= 3; $n++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $pdf .= "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF\n";
        return $pdf;
    }

    public function testSigningInlineAcroFormThrows(): void
    {
        if (!function_exists('openssl_pkcs12_read')) {
            self::markTestSkipped('openssl unavailable');
        }
        $gen = TestCertificate::generate();
        $cred = SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);
        $pdf = PdfEditor::fromBytes($this->inlineAcroFormPdf());
        $pdf->sign($cred, field: 'Sig');
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('~/AcroForm is a direct dictionary~');
        $pdf->output();
    }
}
