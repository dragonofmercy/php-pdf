<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use PHPUnit\Framework\TestCase;

final class FontRegistryTest extends TestCase
{
    public function testEmptyByDefault(): void
    {
        $registry = new FontRegistry();
        self::assertTrue($registry->isEmpty());
        self::assertSame([], $registry->registeredFonts());
    }

    public function testShortNameAssignsF1ToFirstFont(): void
    {
        $registry = new FontRegistry();
        self::assertSame('F1', $registry->shortName(Font::helvetica()));
    }

    public function testShortNameAssignsF2ToSecondDistinctFont(): void
    {
        $registry = new FontRegistry();
        $registry->shortName(Font::helvetica());
        self::assertSame('F2', $registry->shortName(Font::times()));
    }

    public function testSameFontReturnsSameShortName(): void
    {
        $registry = new FontRegistry();
        $a = $registry->shortName(Font::helvetica());
        $b = $registry->shortName(Font::helvetica());
        self::assertSame($a, $b);
    }

    public function testVariantsAreDistinct(): void
    {
        $registry = new FontRegistry();
        $a = $registry->shortName(Font::helvetica());
        $b = $registry->shortName(Font::helvetica()->bold());
        self::assertNotSame($a, $b);
    }

    public function testRegisteredFontsPreservesOrder(): void
    {
        $registry = new FontRegistry();
        $registry->shortName(Font::times());
        $registry->shortName(Font::helvetica());
        $registry->shortName(Font::courier());

        $names = array_map(
            static fn (Font $f): string => $f->pdfName(),
            $registry->registeredFonts(),
        );
        self::assertSame(['Times-Roman', 'Helvetica', 'Courier'], $names);
    }

    public function testIsEmptyAfterRegistrations(): void
    {
        $registry = new FontRegistry();
        $registry->shortName(Font::helvetica());
        self::assertFalse($registry->isEmpty());
    }

    public function testRegistersStandardAndCustomMixed(): void
    {
        $registry = new FontRegistry();
        $h = Font::helvetica();
        $i = Font::custom('Inter');

        $shortH = $registry->shortName($h);
        $shortI = $registry->shortNameForCustom($i, 'Inter-Regular');

        self::assertSame('F1', $shortH);
        self::assertSame('F2', $shortI);
        self::assertCount(1, $registry->registeredFonts());
        self::assertCount(1, $registry->customRegistrations());
    }

    public function testCustomShortNameStableAcrossLookups(): void
    {
        $registry = new FontRegistry();
        $registry->shortNameForCustom(Font::custom('Inter')->bold(), 'Inter-Bold');
        self::assertSame('F1', $registry->shortNameForCustom(Font::custom('Inter')->bold(), 'Inter-Bold'));
    }

    public function testTwoCustomFontsResolvingToSameTtfShareShortName(): void
    {
        $registry = new FontRegistry();
        $first = $registry->shortNameForCustom(Font::custom('Inter')->italic(), 'Inter-Regular');
        $second = $registry->shortNameForCustom(Font::custom('Inter'), 'Inter-Regular');
        self::assertSame($first, $second);
    }

    public function testCustomRegistrationsReturnsResolvedNames(): void
    {
        $registry = new FontRegistry();
        $registry->shortNameForCustom(Font::custom('Inter'), 'Inter-Regular');
        $registry->shortNameForCustom(Font::custom('Inter')->bold(), 'Inter-Bold');
        self::assertSame(['Inter-Regular', 'Inter-Bold'], array_values($registry->customRegistrations()));
    }
}
