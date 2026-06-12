<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\PageFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PageFormatTest extends TestCase
{
    /**
     * @return array<string, array{PageFormat, float, float}>
     */
    public static function formatProvider(): array
    {
        return [
            // ISO A series
            'A0' => [PageFormat::A0, 841.0, 1189.0],
            'A1' => [PageFormat::A1, 594.0, 841.0],
            'A2' => [PageFormat::A2, 420.0, 594.0],
            'A3' => [PageFormat::A3, 297.0, 420.0],
            'A4' => [PageFormat::A4, 210.0, 297.0],
            'A5' => [PageFormat::A5, 148.0, 210.0],
            'A6' => [PageFormat::A6, 105.0, 148.0],
            'A7' => [PageFormat::A7, 74.0, 105.0],
            // ISO B series
            'B4' => [PageFormat::B4, 250.0, 353.0],
            'B5' => [PageFormat::B5, 176.0, 250.0],
            // ISO C series (envelopes)
            'C4' => [PageFormat::C4, 229.0, 324.0],
            'C5' => [PageFormat::C5, 162.0, 229.0],
            'C6' => [PageFormat::C6, 114.0, 162.0],
            'DL' => [PageFormat::DL, 110.0, 220.0],
            // North American
            'LETTER' => [PageFormat::LETTER, 215.9, 279.4],
            'LEGAL' => [PageFormat::LEGAL, 215.9, 355.6],
            'TABLOID' => [PageFormat::TABLOID, 279.4, 431.8],
            'EXECUTIVE' => [PageFormat::EXECUTIVE, 184.15, 266.7],
            'HALF_LETTER' => [PageFormat::HALF_LETTER, 139.7, 215.9],
        ];
    }

    #[DataProvider('formatProvider')]
    public function testDimensionsMm(PageFormat $format, float $expectedWidth, float $expectedHeight): void
    {
        self::assertSame([$expectedWidth, $expectedHeight], $format->dimensionsMm());
    }

    #[DataProvider('formatProvider')]
    public function testPortraitOrientation(PageFormat $format): void
    {
        [$width, $height] = $format->dimensionsMm();
        self::assertLessThanOrEqual($height, $width, 'dimensionsMm() must return portrait (width <= height)');
    }

    public function testEveryCaseIsCovered(): void
    {
        $covered = array_map(static fn (array $row): PageFormat => $row[0], self::formatProvider());
        self::assertEqualsCanonicalizing(PageFormat::cases(), $covered);
    }
}
