<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Fill\Font;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\TtfParser;
use DragonOfMercy\PhpPdf\Form\Fill\Font\CompositeEmbeddedAppearanceFont;
use PHPUnit\Framework\TestCase;

final class CompositeEmbeddedAppearanceFontTest extends TestCase
{
    private const string FREESANS = __DIR__ . '/../../../../Golden/assets/fonts/FreeSans.ttf';

    private static function font(): CompositeEmbeddedAppearanceFont
    {
        $bytes = file_get_contents(self::FREESANS);
        self::assertIsString($bytes);
        return new CompositeEmbeddedAppearanceFont(TtfParser::parse($bytes, 'test'), 'fld');
    }

    public function testMeasureWidthMatchesFontAdvances(): void
    {
        $bytes = file_get_contents(self::FREESANS);
        self::assertIsString($bytes);
        $ttf = TtfParser::parse($bytes, 'test');

        $expectedEm = 0;
        foreach ([ord('A'), ord('B')] as $cp) {
            $gid = $ttf->cmap[$cp];
            $expectedEm += $ttf->advanceWidthsByGid[$gid];
        }
        $expected = $expectedEm * 12.0 / $ttf->unitsPerEm;

        self::assertEqualsWithDelta($expected, self::font()->measureWidth('AB', 12.0), 1e-9);
    }

    public function testEncodeShowOperandIsTwoByteGidHex(): void
    {
        $bytes = file_get_contents(self::FREESANS);
        self::assertIsString($bytes);
        $ttf = TtfParser::parse($bytes, 'test');

        $hex = '';
        foreach ([ord('A'), ord('B')] as $cp) {
            $gid = $ttf->cmap[$cp];
            $hex .= strtoupper(bin2hex(chr(($gid >> 8) & 0xFF) . chr($gid & 0xFF)));
        }

        self::assertSame('<' . $hex . '>', self::font()->encodeShowOperand('AB'));
    }

    public function testMissingGlyphThrowsNamingField(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('fld');
        // U+10FFFF is a noncharacter absent from FreeSans (no glyph -> gid 0).
        self::font()->encodeShowOperand("\u{10FFFF}");
    }
}
