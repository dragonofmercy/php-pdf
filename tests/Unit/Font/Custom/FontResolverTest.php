<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\Custom\FontResolver;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\CustomFontEngine;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Font\StandardFontEngine;
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

    public function testResolveEngineReturnsStandardEngineForStandardFont(): void
    {
        $resolver = new FontResolver([], new MetricsRegistry());
        $engine = $resolver->resolveEngine(Font::helvetica());
        self::assertInstanceOf(StandardFontEngine::class, $engine);
    }

    public function testResolveEngineReturnsCustomEngineForCustomFont(): void
    {
        $reg = ['Inter' => [
            'regular' => $this->ttf('Inter-Regular'),
            'bold' => null, 'italic' => null, 'boldItalic' => null,
        ]];
        $resolver = new FontResolver($reg, new MetricsRegistry());
        $engine = $resolver->resolveEngine(Font::custom('Inter'));
        self::assertInstanceOf(CustomFontEngine::class, $engine);
    }

    public function testResolveEngineCachesByFontIdentity(): void
    {
        $resolver = new FontResolver([], new MetricsRegistry());
        $f = Font::helvetica();
        $first = $resolver->resolveEngine($f);
        $second = $resolver->resolveEngine($f);
        self::assertSame($first, $second);
    }

    public function testResolveEngineForUnregisteredCustomFontThrows(): void
    {
        $resolver = new FontResolver([], new MetricsRegistry());
        $this->expectException(PdfException::class);
        $resolver->resolveEngine(Font::custom('Missing'));
    }
}
