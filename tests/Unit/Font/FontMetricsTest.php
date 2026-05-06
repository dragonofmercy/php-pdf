<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Font;

use PhpPdf\Font\FontMetrics;
use PHPUnit\Framework\TestCase;

final class FontMetricsTest extends TestCase
{
    private function fixture(): FontMetrics
    {
        // Synthetic widths just for unit testing — not real AFM data.
        return new FontMetrics(
            ascent: 718,
            descent: -207,
            capHeight: 718,
            xHeight: 523,
            missingWidth: 250,
            widths: [
                0x20 => 278,   // space
                0x41 => 667,   // 'A'
                0x42 => 667,   // 'B'
                0x61 => 556,   // 'a'
                0xE9 => 556,   // 'é'
            ],
        );
    }

    public function testCharWidthAtSize12(): void
    {
        $m = $this->fixture();
        // 278 / 1000 * 12 = 3.336
        self::assertEqualsWithDelta(3.336, $m->charWidth(0x20, 12.0), 0.0001);
    }

    public function testCharWidthMissingByteUsesMissingWidth(): void
    {
        $m = $this->fixture();
        // missingWidth=250, at size 10: 250 / 1000 * 10 = 2.5
        self::assertEqualsWithDelta(2.5, $m->charWidth(0x7F, 10.0), 0.0001);
    }

    public function testStringWidthEmptyIsZero(): void
    {
        self::assertSame(0.0, $this->fixture()->stringWidth('', 12.0));
    }

    public function testStringWidthAscii(): void
    {
        $m = $this->fixture();
        // 'A' + 'B' = 667 + 667 = 1334; at 12pt = 1334 / 1000 * 12 = 16.008
        self::assertEqualsWithDelta(16.008, $m->stringWidth("AB", 12.0), 0.0001);
    }

    public function testStringWidthLatin1Byte(): void
    {
        $m = $this->fixture();
        // 'é' encoded byte is 0xE9 in WinAnsi → 556
        self::assertEqualsWithDelta(556 * 12.0 / 1000.0, $m->stringWidth("\xE9", 12.0), 0.0001);
    }

    public function testAscentAtSize(): void
    {
        $m = $this->fixture();
        // 718 / 1000 * 12 = 8.616
        self::assertEqualsWithDelta(8.616, $m->ascentAt(12.0), 0.0001);
    }

    public function testDescentAtSize(): void
    {
        $m = $this->fixture();
        // -207 / 1000 * 12 = -2.484
        self::assertEqualsWithDelta(-2.484, $m->descentAt(12.0), 0.0001);
    }
}
