<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class PageTreeTest extends TestCase
{
    /** @param non-empty-array<int, string> $objects */
    private static function buildPdf(array $objects, string $header = "%PDF-1.6\n"): string
    {
        $body = $header;
        $offsets = [];
        foreach ($objects as $number => $payload) {
            $offsets[$number] = strlen($body);
            $body .= "{$number} 0 obj\n{$payload}\nendobj\n";
        }
        $size = max(array_keys($objects)) + 1;
        $xrefAt = strlen($body);
        $body .= "xref\n0 1\n0000000000 65535 f \n";
        foreach ($offsets as $number => $offset) {
            $body .= "{$number} 1\n" . sprintf("%010d 00000 n \n", $offset);
        }
        $body .= "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xrefAt}\n%%EOF\n";
        return $body;
    }

    public function testFlatPageTreeWithInheritedAttributes(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 /MediaBox [0 0 595 842] /Resources << /Font << /F1 9 0 R >> >> /Rotate 90 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Contents 5 0 R >>',
            4 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Rotate 0 >>',
            5 => "<< /Length 2 >>\nstream\nBT\nendstream",
            9 => '<< /Type /Font >>',
        ]));

        self::assertSame(2, $reader->pageCount());

        $page1 = $reader->page(1);
        self::assertSame([0.0, 0.0, 595.0, 842.0], $page1->mediaBox);   // inherited
        self::assertSame(90, $page1->rotate);                            // inherited
        self::assertNotNull($page1->resources);                          // inherited
        self::assertEquals([PdfReference::to(5, 0)], $page1->contents);

        $page2 = $reader->page(2);
        self::assertSame([0.0, 0.0, 200.0, 200.0], $page2->mediaBox);   // own value overrides
        self::assertSame(0, $page2->rotate);
        self::assertSame([], $page2->contents);
    }

    public function testNestedPagesNodesAndIntermediateOverride(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 2 /MediaBox [0 0 612 792] >>',
            3 => '<< /Type /Pages /Parent 2 0 R /Kids [4 0 R 5 0 R] /Count 2 /MediaBox [0 0 300 300] >>',
            4 => '<< /Type /Page /Parent 3 0 R >>',
            5 => '<< /Type /Page /Parent 3 0 R >>',
        ]));
        self::assertSame(2, $reader->pageCount());
        self::assertSame([0.0, 0.0, 300.0, 300.0], $reader->page(1)->mediaBox);
        self::assertSame([0.0, 0.0, 300.0, 300.0], $reader->page(2)->mediaBox);
    }

    public function testContentsArrayAndCropBox(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 612 792] >>',
            3 => '<< /Type /Page /Parent 2 0 R /CropBox [10 10 600 780] /Contents [4 0 R 5 0 R] >>',
            4 => "<< /Length 1 >>\nstream\nq\nendstream",
            5 => "<< /Length 1 >>\nstream\nQ\nendstream",
        ]));
        $page = $reader->page(1);
        self::assertSame([10.0, 10.0, 600.0, 780.0], $page->cropBox);
        self::assertSame([10.0, 10.0, 600.0, 780.0], $page->box());
        self::assertEquals([PdfReference::to(4, 0), PdfReference::to(5, 0)], $page->contents);
    }

    public function testBoxFallsBackToMediaBoxAndCoordinatesAreNormalized(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [612 792 0 0] >>',
            3 => '<< /Type /Page /Parent 2 0 R >>',
        ]));
        $page = $reader->page(1);
        self::assertNull($page->cropBox);
        self::assertSame([0.0, 0.0, 612.0, 792.0], $page->box());
    }

    public function testMissingMediaBoxFallsBackToLetter(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R >>',
        ]));
        self::assertSame([0.0, 0.0, 612.0, 792.0], $reader->page(1)->mediaBox);
    }

    public function testRotateIsNormalized(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 /MediaBox [0 0 100 100] >>',
            3 => '<< /Type /Page /Parent 2 0 R /Rotate -90 >>',
            4 => '<< /Type /Page /Parent 2 0 R /Rotate 450 >>',
        ]));
        self::assertSame(270, $reader->page(1)->rotate);
        self::assertSame(90, $reader->page(2)->rotate);
    }

    public function testPageOutOfRangeThrows(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 100 100] >>',
            3 => '<< /Type /Page /Parent 2 0 R >>',
        ]));
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('out of range');
        $reader->page(2);
    }

    public function testKidsCycleIsDetected(): void
    {
        $pdf = self::buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Pages /Parent 2 0 R /Kids [2 0 R] /Count 1 >>',
        ]);
        $reader = PdfReader::fromBytes($pdf);
        $this->expectException(PdfParseException::class);
        $this->expectExceptionMessage('cycle');
        $reader->pageCount();
    }
}
