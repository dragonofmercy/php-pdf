<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\PdfA;

use DragonOfMercy\PhpPdf\PdfA\PdfALevel;
use PHPUnit\Framework\TestCase;

final class PdfALevelTest extends TestCase
{
    public function testPartNumbers(): void
    {
        self::assertSame(2, PdfALevel::A2B->part());
        self::assertSame(2, PdfALevel::A2U->part());
        self::assertSame(3, PdfALevel::A3B->part());
        self::assertSame(3, PdfALevel::A3U->part());
    }

    public function testConformanceLetters(): void
    {
        self::assertSame('B', PdfALevel::A2B->conformance());
        self::assertSame('U', PdfALevel::A2U->conformance());
        self::assertSame('B', PdfALevel::A3B->conformance());
        self::assertSame('U', PdfALevel::A3U->conformance());
    }

    public function testAllowsEmbeddedFilesOnlyForPart3(): void
    {
        self::assertFalse(PdfALevel::A2B->allowsEmbeddedFiles());
        self::assertFalse(PdfALevel::A2U->allowsEmbeddedFiles());
        self::assertTrue(PdfALevel::A3B->allowsEmbeddedFiles());
        self::assertTrue(PdfALevel::A3U->allowsEmbeddedFiles());
    }

    public function testRequiresUnicodeOnlyForU(): void
    {
        self::assertFalse(PdfALevel::A2B->requiresUnicode());
        self::assertTrue(PdfALevel::A2U->requiresUnicode());
        self::assertFalse(PdfALevel::A3B->requiresUnicode());
        self::assertTrue(PdfALevel::A3U->requiresUnicode());
    }

    public function testLevelAConformanceAndPart(): void
    {
        self::assertSame(2, PdfALevel::A2A->part());
        self::assertSame(3, PdfALevel::A3A->part());
        self::assertSame('A', PdfALevel::A2A->conformance());
        self::assertSame('A', PdfALevel::A3A->conformance());
    }

    public function testLevelARequiresTaggingAndUnicode(): void
    {
        self::assertTrue(PdfALevel::A2A->requiresTagging());
        self::assertTrue(PdfALevel::A3A->requiresTagging());
        self::assertTrue(PdfALevel::A2A->requiresUnicode());
        self::assertTrue(PdfALevel::A3A->requiresUnicode());

        self::assertFalse(PdfALevel::A2B->requiresTagging());
        self::assertFalse(PdfALevel::A2U->requiresTagging());
        self::assertFalse(PdfALevel::A2B->requiresUnicode());
        self::assertTrue(PdfALevel::A2U->requiresUnicode());
    }

    public function testEmbeddedFilesOnlyAtPart3(): void
    {
        self::assertTrue(PdfALevel::A3A->allowsEmbeddedFiles());
        self::assertFalse(PdfALevel::A2A->allowsEmbeddedFiles());
    }
}
