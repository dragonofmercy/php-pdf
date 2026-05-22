<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\Orientation;
use DragonOfMercy\PhpPdf\Barcode\Renderer;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class RendererOrientedTest extends TestCase
{
    public function testHorizontalRunsClosureWithoutTransform(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();

        Renderer::oriented($page, Orientation::Horizontal, 10.0, 20.0, 30.0, 12.0, function () use ($page): void {
            $page->contentStream()->append("MARKER\n");
        });

        $bytes = $page->contentStream()->bytes();
        self::assertStringContainsString("MARKER\n", $bytes);
        self::assertStringNotContainsString("q\n", $bytes);
        self::assertStringNotContainsString("0 1 -1 0", $bytes);
    }

    public function testVerticalWrapsClosureInRotationTransform(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();

        // tx = xPt + yPt + hPt = 10 + 20 + 12 = 42 ; ty = yPt - xPt = 20 - 10 = 10
        Renderer::oriented($page, Orientation::Vertical, 10.0, 20.0, 30.0, 12.0, function () use ($page): void {
            $page->contentStream()->append("MARKER\n");
        });

        $bytes = $page->contentStream()->bytes();
        self::assertStringContainsString("q\n0 1 -1 0 42 10 cm\nMARKER\nQ\n", $bytes);
    }

    public function testVerticalBalancesSaveRestoreWhenClosureThrows(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();

        try {
            Renderer::oriented($page, Orientation::Vertical, 10.0, 20.0, 30.0, 12.0, function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        // The outer save (q) must be matched by a restore (Q) even though the
        // closure threw - otherwise an orphan 'q' corrupts later page content.
        $bytes = $page->contentStream()->bytes();
        self::assertSame(substr_count($bytes, "q\n"), substr_count($bytes, "Q\n"));
    }
}
