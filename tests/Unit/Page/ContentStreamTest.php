<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Page;

use PhpPdf\Page\ContentStream;
use PHPUnit\Framework\TestCase;

final class ContentStreamTest extends TestCase
{
    public function testEmptyByDefault(): void
    {
        $cs = new ContentStream(pageHeight: 841.89);
        self::assertTrue($cs->isEmpty());
        self::assertSame('', $cs->bytes());
    }

    public function testBytesPrependFlipCtmWhenNonEmpty(): void
    {
        $cs = new ContentStream(pageHeight: 841.89);
        $cs->append("100 100 10 10 re\n");
        $out = $cs->bytes();

        self::assertStringStartsWith("1 0 0 -1 0 841.89 cm\n", $out);
        self::assertStringContainsString("100 100 10 10 re\n", $out);
    }

    public function testAppendAccumulates(): void
    {
        $cs = new ContentStream(pageHeight: 100);
        $cs->append("a\n");
        $cs->append("b\n");
        self::assertStringEndsWith("a\nb\n", $cs->bytes());
    }

    public function testFlipUsesConfiguredPageHeight(): void
    {
        $cs = new ContentStream(pageHeight: 595.28);
        $cs->append("x");
        self::assertStringStartsWith("1 0 0 -1 0 595.28 cm\n", $cs->bytes());
    }
}
