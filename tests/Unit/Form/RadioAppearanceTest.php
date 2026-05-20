<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Form\RadioAppearance;
use PHPUnit\Framework\TestCase;

final class RadioAppearanceTest extends TestCase
{
    public function testOnStreamContainsFourBezierCurves(): void
    {
        $result = RadioAppearance::generate(widthPt: 14.17, heightPt: 14.17, textColor: Color::rgb(0, 0, 0));
        self::assertSame(1, substr_count($result['onContent'], "\nm\n") + substr_count($result['onContent'], ' m'), 'expected one moveto');
        self::assertSame(4, substr_count($result['onContent'], ' c'), 'expected 4 cubic Bezier curves');
        self::assertStringContainsString(' f', $result['onContent']);
        self::assertStringContainsString('0 0 0 rg', $result['onContent']);
    }

    public function testOffStreamIsEffectivelyEmpty(): void
    {
        $result = RadioAppearance::generate(14.17, 14.17, Color::rgb(0, 0, 0));
        self::assertSame('', trim($result['offContent']));
    }

    public function testBboxMatchesDimensions(): void
    {
        $result = RadioAppearance::generate(14.17, 8.5, Color::rgb(0, 0, 0));
        self::assertSame([0.0, 0.0, 14.17, 8.5], $result['bbox']);
    }

    public function testCustomColorEmitted(): void
    {
        $result = RadioAppearance::generate(14.17, 14.17, Color::rgb(0, 0, 255));
        self::assertStringContainsString('0 0 1 rg', $result['onContent']);
    }
}
