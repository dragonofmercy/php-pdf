<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Pdf;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Tests\Support\TestTsa;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PdfSignExistingTimestampTest extends TestCase
{
    private function sourcePdf(): string
    {
        $doc = new Document();
        $doc->addPage();
        return $doc->output();
    }

    public function testDocumentTimestampStackedOnOpenedPdf(): void
    {
        $source = $this->sourcePdf();
        $pdf = Pdf::fromBytes($source);
        $pdf->addDocumentTimestamp(Tsa::withClient(new TestTsa()));
        $bytes = $pdf->output();

        self::assertSame($source, substr($bytes, 0, strlen($source)), 'source is a byte-for-byte prefix');
        self::assertStringContainsString('/DocTimeStamp', $bytes);
        self::assertSame(1, substr_count($bytes, '/ByteRange'));
    }

    public function testStackedTimestampQpdfClean(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf unavailable');
        }
        $pdf = Pdf::fromBytes($this->sourcePdf());
        $pdf->addDocumentTimestamp(Tsa::withClient(new TestTsa()));
        $file = (string) tempnam(sys_get_temp_dir(), 'pdfts');
        try {
            file_put_contents($file, $pdf->output());
            $p = new Process([$qpdf, '--check', $file]);
            $p->run();
            self::assertNotSame(2, $p->getExitCode(),
                'qpdf structural errors: ' . $p->getOutput() . $p->getErrorOutput());
        } finally {
            @unlink($file);
        }
    }
}
