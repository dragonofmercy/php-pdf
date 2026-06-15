<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Fill;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Form\Fill\DefaultAppearance;
use DragonOfMercy\PhpPdf\Form\Fill\Font\Standard14AppearanceFont;
use DragonOfMercy\PhpPdf\Form\Fill\ListboxAppearanceBuilder;
use PHPUnit\Framework\TestCase;

final class ListboxAppearanceBuilderTest extends TestCase
{
    private function helvetica(): Standard14AppearanceFont
    {
        return new Standard14AppearanceFont(Font::helvetica(), new MetricsRegistry());
    }

    public function testBuildsCorrectScaffoldAndHighlights(): void
    {
        $da = DefaultAppearance::parse('0 g /Helv 10 Tf');
        $builder = new ListboxAppearanceBuilder();

        $result = $builder->build(
            displayOptions: ['Option A', 'Option B', 'Option C'],
            selectedIndices: [0, 2],
            w: 80.0,
            h: 40.0,
            da: $da,
            alias: 'Helv',
            font: $this->helvetica(),
        );

        $content = $result['content'];
        $bbox = $result['bbox'];

        // BBox must be [0, 0, w, h]
        self::assertSame(0.0, $bbox[0]);
        self::assertSame(0.0, $bbox[1]);
        self::assertSame(80.0, $bbox[2]);
        self::assertSame(40.0, $bbox[3]);

        // Scaffold: must open with /Tx BMC and close with EMC
        self::assertStringContainsString('/Tx BMC', $content);
        self::assertStringContainsString('EMC', $content);

        // Must contain exactly 3 Tj operators (one per option)
        $tjCount = substr_count($content, ' Tj');
        self::assertSame(3, $tjCount, 'Must have exactly 3 Tj operators');

        // Must contain highlight fills for indices 0 and 2 (2 highlights)
        // Each highlight: a rect followed by ' f'
        $fCount = substr_count($content, ' f');
        self::assertGreaterThanOrEqual(2, $fCount, 'Must have at least 2 fill operators for 2 highlighted lines');

        // Must contain the clip rect
        self::assertStringContainsString('W n', $content);

        // Highlight color must be present
        self::assertStringContainsString('0.6 0.847 1 rg', $content);

        // Option texts must appear
        self::assertStringContainsString('Option A', $content);
        self::assertStringContainsString('Option B', $content);
        self::assertStringContainsString('Option C', $content);
    }

    public function testAutoSizeFallsBackToTen(): void
    {
        $da = DefaultAppearance::parse('0 g /Helv 0 Tf'); // auto-size
        $builder = new ListboxAppearanceBuilder();

        $result = $builder->build(
            displayOptions: ['A'],
            selectedIndices: [],
            w: 60.0,
            h: 20.0,
            da: $da,
            alias: 'Helv',
            font: $this->helvetica(),
        );

        // With auto-size, size should fall back to 10; check that 'Tf' is still present
        self::assertStringContainsString('Tf', $result['content']);
        self::assertStringContainsString('/Tx BMC', $result['content']);
    }
}
