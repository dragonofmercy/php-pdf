<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\PdfA;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\PdfA\PdfAConformanceGuard;
use PHPUnit\Framework\TestCase;

final class PdfAConformanceGuardTest extends TestCase
{
    public function testPassesWhenNothingForbidden(): void
    {
        (new PdfAConformanceGuard())->verify(
            standardFonts: [],
            hasEncryption: false,
            hasAppendedRevisions: false,
            hasDocumentScripts: false,
        );
        $this->expectNotToPerformAssertions();
    }

    public function testThrowsOnNonEmbeddedStandardFont(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Helvetica');
        (new PdfAConformanceGuard())->verify(
            standardFonts: [Font::helvetica()],
            hasEncryption: false,
            hasAppendedRevisions: false,
            hasDocumentScripts: false,
        );
    }

    public function testThrowsOnEncryption(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('encrypt');
        (new PdfAConformanceGuard())->verify([], true, false, false);
    }

    public function testThrowsOnAppendedRevisions(): void
    {
        $this->expectException(PdfException::class);
        (new PdfAConformanceGuard())->verify([], false, true, false);
    }

    public function testThrowsOnDocumentScripts(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('JavaScript');
        (new PdfAConformanceGuard())->verify([], false, false, true);
    }
}
