<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Modify;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Pdf;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PdfAppendPageTest extends TestCase
{
    private static function source(int $pages = 1): string
    {
        $doc = new Document(Unit::PT);
        for ($i = 0; $i < $pages; $i++) {
            $doc->addPage();
        }
        return $doc->output();
    }

    public function testAppendedPageIncreasesPageCount(): void
    {
        $pdf = Pdf::fromBytes(self::source(2));
        $page = $pdf->appendPage();
        $page->setFont(Font::helvetica(), 12);
        $page->text(50, 50, 'Appended content');
        $bytes = $pdf->output();

        self::assertSame(self::source(2), substr($bytes, 0, strlen(self::source(2))));
        $reader = PdfReader::fromBytes($bytes);
        self::assertSame(3, $reader->pageCount());
        $contents = $reader->page(3)->contents;
        self::assertNotSame([], $contents);
        $stream = $reader->resolve($contents[0]);
        self::assertInstanceOf(ReadStream::class, $stream);
        $content = $reader->decodeStream($stream);
        self::assertStringContainsString('Appended content', $content);
    }

    public function testAppendedPageHasOwnAttributesAndRotateZero(): void
    {
        $pdf = Pdf::fromBytes(self::source());
        $pdf->appendPage();
        $reader = PdfReader::fromBytes($pdf->output());
        $page = $reader->page(2);
        self::assertSame(0, $page->rotate);
        self::assertNotNull($page->resources);
        // A4 portrait default
        self::assertEqualsWithDelta(595.28, $page->mediaBox[2], 0.01);
    }

    public function testExistingPagesAreUntouched(): void
    {
        $source = self::source(2);
        $pdf = Pdf::fromBytes($source);
        $pdf->appendPage();
        $bytes = $pdf->output();
        $reader = PdfReader::fromBytes($bytes);
        // original pages still parse with their original boxes
        self::assertEqualsWithDelta(595.28, $reader->page(1)->mediaBox[2], 0.01);
        self::assertSame(3, $reader->pageCount());
    }

    public function testMultiplePagesAndMetadataInOneRevision(): void
    {
        $pdf = Pdf::fromBytes(self::source());
        $pdf->setTitle('With pages');
        $pdf->appendPage();
        $pdf->appendPage();
        $reader = PdfReader::fromBytes($pdf->output());
        self::assertSame(3, $reader->pageCount());
    }

    public function testImagesWorkOnAppendedPages(): void
    {
        $pdf = Pdf::fromBytes(self::source());
        $page = $pdf->appendPage();
        $page->image(self::anyJpegAsset(), 10, 10, 100);
        $reader = PdfReader::fromBytes($pdf->output());
        self::assertSame(2, $reader->pageCount());
        $resources = $reader->page(2)->resources;
        self::assertNotNull($resources);
        self::assertNotNull($resources->get(Name::of('XObject')));
    }

    public function testAppendToXrefStreamSource(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed.');
        }
        $in = tempnam(sys_get_temp_dir(), 'phppdf_in_');
        $out = tempnam(sys_get_temp_dir(), 'phppdf_out_');
        self::assertIsString($in);
        self::assertIsString($out);
        try {
            file_put_contents($in, self::source());
            (new Process([$qpdf, '--object-streams=generate', $in, $out]))->run();

            $pdf = Pdf::open($out);
            $page = $pdf->appendPage();
            $page->setFont(Font::helvetica(), 12);
            $page->text(50, 50, 'On a stream source');
            $bytes = $pdf->output();

            $reader = PdfReader::fromBytes($bytes);
            self::assertTrue($reader->usesXrefStreams());
            self::assertSame(2, $reader->pageCount());

            $check = tempnam(sys_get_temp_dir(), 'phppdf_chk_');
            self::assertIsString($check);
            file_put_contents($check, $bytes);
            $verify = new Process([$qpdf, '--check', $check]);
            $verify->run();
            self::assertSame(0, $verify->getExitCode(), $verify->getOutput() . $verify->getErrorOutput());
            @unlink($check);
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    private static function anyJpegAsset(): string
    {
        return __DIR__ . '/../../Golden/assets/jpeg-rgb-32x16.jpg';
    }
}
