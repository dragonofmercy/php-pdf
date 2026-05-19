<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\GlyphClosure;
use DragonOfMercy\PhpPdf\Font\Custom\SfntReader;
use DragonOfMercy\PhpPdf\Font\Custom\TtfParser;
use PHPUnit\Framework\TestCase;

final class GlyphClosureTest extends TestCase
{
    private const string FREESANS = __DIR__ . '/../../../Golden/fixtures/fonts/FreeSans.ttf';

    public function testGid0AlwaysIncludedEvenWhenNotRequested(): void
    {
        $bytes = $this->minimalSfnt();
        $closure = GlyphClosure::expand($bytes, [2 => true], 'Synthetic');
        self::assertArrayHasKey(0, $closure);
        self::assertArrayHasKey(2, $closure);
    }

    public function testSimpleGlyphHasNoExtraComponents(): void
    {
        $bytes = $this->minimalSfnt();
        $closure = GlyphClosure::expand($bytes, [1 => true], 'Synthetic');
        self::assertSame([1 => true, 0 => true], $closure);
    }

    public function testCompositeGlyphPullsInComponentsTransitively(): void
    {
        $bytes = $this->minimalSfnt();
        $closure = GlyphClosure::expand($bytes, [2 => true], 'Synthetic');
        self::assertArrayHasKey(1, $closure);
        self::assertArrayHasKey(2, $closure);
        self::assertArrayHasKey(3, $closure);
        self::assertArrayHasKey(0, $closure);
    }

    public function testComponentOutOfRangeThrowsWithContext(): void
    {
        $bytes = $this->minimalSfnt(badComponentGid: 999);
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Corrupt composite glyph in font 'Synthetic': component GID 999 out of range");
        GlyphClosure::expand($bytes, [2 => true], 'Synthetic');
    }

    public function testRealFontAccentedLetterIsClosed(): void
    {
        if (!is_file(self::FREESANS)) {
            self::markTestSkipped('FreeSans fixture absent');
        }
        $raw = file_get_contents(self::FREESANS);
        self::assertIsString($raw);
        $ttf = TtfParser::parse($raw, 'FreeSans');
        $eAcute = $ttf->cmap[0x00E9];
        $closure = GlyphClosure::expand($raw, [$eAcute => true], 'FreeSans');
        self::assertArrayHasKey($eAcute, $closure);
        self::assertArrayHasKey(0, $closure);
        self::assertGreaterThanOrEqual(3, count($closure));
    }

    private function minimalSfnt(int $badComponentGid = 3): string
    {
        $numGlyphs = 4;

        $simple = pack('n', 1) . str_repeat("\x00", 8);
        $compTo = static fn (int $g): string =>
            pack('n', 0xFFFF) . str_repeat("\x00", 8)
            . pack('n', 0x0000) . pack('n', $g) . "\x00\x00";
        $g0 = '';
        $g1 = $simple;
        $g2 = $compTo($badComponentGid);
        $g3 = $compTo(1);

        $glyf = $g0 . $g1 . $g2 . $g3;
        $loca = pack(
            'N5',
            0,
            strlen($g0),
            strlen($g0 . $g1),
            strlen($g0 . $g1 . $g2),
            strlen($glyf),
        );

        $head = str_repeat("\x00", 54);
        $head = substr_replace($head, pack('n', 1), 50, 2);

        $maxp = "\x00\x01\x00\x00" . pack('n', $numGlyphs);

        $tables = [
            'head' => $head,
            'maxp' => $maxp,
            'loca' => $loca,
            'glyf' => $glyf,
        ];
        ksort($tables);

        $numTables = count($tables);
        $offsetTable = "\x00\x01\x00\x00" . pack('n', $numTables) . "\x00\x00\x00\x00\x00\x00";
        $dirSize = $numTables * 16;
        $running = 12 + $dirSize;
        $directory = '';
        $body = '';
        foreach ($tables as $tag => $data) {
            $pad = (4 - strlen($data) % 4) % 4;
            $directory .= $tag . "\x00\x00\x00\x00" . pack('N', $running) . pack('N', strlen($data));
            $body .= $data . str_repeat("\x00", $pad);
            $running += strlen($data) + $pad;
        }
        return $offsetTable . $directory . $body;
    }
}
