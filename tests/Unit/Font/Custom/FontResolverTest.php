<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\Custom\FontResolver;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use PHPUnit\Framework\TestCase;

final class FontResolverTest extends TestCase
{
    private function ttf(string $name): ParsedTtf
    {
        return new ParsedTtf('', $name, 1000, 800, -200, 700, 500, [0, 0, 1000, 1000], 0, 400, 32, [], []);
    }

    public function testUnregisteredAliasThrows(): void
    {
        $resolver = new FontResolver([]);
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Font alias 'Inter' is not registered");
        $resolver->resolve(Font::custom('Inter'));
    }

    public function testRegularOnlyReturnsRegularForAllVariants(): void
    {
        $reg = $this->ttf('Reg');
        $resolver = new FontResolver([
            'Inter' => ['regular' => $reg, 'bold' => null, 'italic' => null, 'boldItalic' => null],
        ]);
        self::assertSame($reg, $resolver->resolve(Font::custom('Inter')));
        self::assertSame($reg, $resolver->resolve(Font::custom('Inter')->bold()));
        self::assertSame($reg, $resolver->resolve(Font::custom('Inter')->italic()));
        self::assertSame($reg, $resolver->resolve(Font::custom('Inter')->bold()->italic()));
    }

    public function testRegularPlusBoldGivesBoldForItalicFallback(): void
    {
        $reg = $this->ttf('Reg');
        $bold = $this->ttf('Bold');
        $resolver = new FontResolver([
            'Inter' => ['regular' => $reg, 'bold' => $bold, 'italic' => null, 'boldItalic' => null],
        ]);
        self::assertSame($reg, $resolver->resolve(Font::custom('Inter')->italic()));
        self::assertSame($bold, $resolver->resolve(Font::custom('Inter')->bold()));
        self::assertSame($bold, $resolver->resolve(Font::custom('Inter')->bold()->italic()));
    }

    public function testFullFamilyReturnsExactMatches(): void
    {
        $reg = $this->ttf('Reg');
        $bold = $this->ttf('Bold');
        $italic = $this->ttf('Ital');
        $boldItalic = $this->ttf('BItal');
        $resolver = new FontResolver([
            'Inter' => ['regular' => $reg, 'bold' => $bold, 'italic' => $italic, 'boldItalic' => $boldItalic],
        ]);
        self::assertSame($reg, $resolver->resolve(Font::custom('Inter')));
        self::assertSame($bold, $resolver->resolve(Font::custom('Inter')->bold()));
        self::assertSame($italic, $resolver->resolve(Font::custom('Inter')->italic()));
        self::assertSame($boldItalic, $resolver->resolve(Font::custom('Inter')->bold()->italic()));
    }

    public function testStandardFontRaisesLogicException(): void
    {
        $resolver = new FontResolver([]);
        $this->expectException(\LogicException::class);
        $resolver->resolve(Font::helvetica());
    }
}
