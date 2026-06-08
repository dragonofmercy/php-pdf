<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Document;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtfCache;
use PHPUnit\Framework\TestCase;

final class FontParseCacheIntegrationTest extends TestCase
{
    private const string FONT = __DIR__ . '/../../Golden/assets/fonts/FreeSans.ttf';

    protected function setUp(): void
    {
        ParsedTtfCache::clear();
    }

    protected function tearDown(): void
    {
        ParsedTtfCache::clear();
    }

    public function testTwoDocumentsShareTheSameParsedTtf(): void
    {
        $a = new Document();
        $a->registerFontFamily('FS', regular: self::FONT);

        $b = new Document();
        $b->registerFontFamily('FS', regular: self::FONT);

        $resolverA = $a->fontResolver();
        $resolverB = $b->fontResolver();
        self::assertNotNull($resolverA);
        self::assertNotNull($resolverB);

        self::assertSame(
            $resolverA->resolve(Font::custom('FS')),
            $resolverB->resolve(Font::custom('FS')),
        );
    }

    public function testClearForcesAFreshParse(): void
    {
        $a = new Document();
        $a->registerFontFamily('FS', regular: self::FONT);
        $resolverA = $a->fontResolver();
        self::assertNotNull($resolverA);
        $parsedA = $resolverA->resolve(Font::custom('FS'));

        ParsedTtfCache::clear();

        $b = new Document();
        $b->registerFontFamily('FS', regular: self::FONT);
        $resolverB = $b->fontResolver();
        self::assertNotNull($resolverB);
        $parsedB = $resolverB->resolve(Font::custom('FS'));

        self::assertNotSame($parsedA, $parsedB);
    }
}
