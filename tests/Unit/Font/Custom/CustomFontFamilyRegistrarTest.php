<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\CustomFontFamilyRegistrar;
use DragonOfMercy\PhpPdf\Font\Custom\FontResolver;
use DragonOfMercy\PhpPdf\Font\Custom\GlyphUsage;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use PHPUnit\Framework\TestCase;

final class CustomFontFamilyRegistrarTest extends TestCase
{
    private const string FREESANS = __DIR__ . '/../../../Golden/assets/fonts/FreeSans.ttf';
    private const string FREESANS_BOLD = __DIR__ . '/../../../Golden/assets/fonts/FreeSansBold.ttf';

    public function testRegisterPopulatesFamiliesAndReturnsResolver(): void
    {
        $families = [];
        $resolver = CustomFontFamilyRegistrar::register(
            $families,
            'sans',
            self::FREESANS,
            self::FREESANS_BOLD,
            null,
            null,
            new MetricsRegistry(),
            new GlyphUsage(),
        );

        self::assertInstanceOf(FontResolver::class, $resolver);
        self::assertArrayHasKey('sans', $families);
        self::assertInstanceOf(ParsedTtf::class, $families['sans']['regular']);
        self::assertNotNull($families['sans']['bold']);
        self::assertNull($families['sans']['italic']);
        self::assertNull($families['sans']['boldItalic']);
    }

    public function testDuplicateAliasThrows(): void
    {
        $families = [];
        // Register once to populate families
        CustomFontFamilyRegistrar::register(
            $families,
            'sans',
            self::FREESANS,
            null,
            null,
            null,
            new MetricsRegistry(),
            new GlyphUsage(),
        );
        // Register again with same alias to trigger duplicate guard
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('is already registered');
        CustomFontFamilyRegistrar::register(
            $families,
            'sans',
            self::FREESANS,
            null,
            null,
            null,
            new MetricsRegistry(),
            new GlyphUsage(),
        );
    }

    public function testMissingFileThrows(): void
    {
        $families = [];
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Font file not found');
        CustomFontFamilyRegistrar::register(
            $families,
            'sans',
            __DIR__ . '/does-not-exist.ttf',
            null,
            null,
            null,
            new MetricsRegistry(),
            new GlyphUsage(),
        );
    }
}
