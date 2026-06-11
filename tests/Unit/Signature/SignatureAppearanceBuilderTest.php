<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Signature\SignatureAppearance;
use DragonOfMercy\PhpPdf\Signature\SignatureAppearanceBuilder;
use PHPUnit\Framework\TestCase;

final class SignatureAppearanceBuilderTest extends TestCase
{
    public function testBuildReturnsContentAndBbox(): void
    {
        $ap = new SignatureAppearance(0, 10.0, 10.0, 100.0, 40.0, "Signed by Alice\n2026-06-11");
        $built = (new SignatureAppearanceBuilder())->build($ap);
        self::assertSame([0.0, 0.0, 100.0, 40.0], $built['bbox']);
        self::assertStringContainsString('BT', $built['content']);
        self::assertStringContainsString('(Signed by Alice)', $built['content']);
        self::assertStringContainsString('(2026-06-11)', $built['content']);
        self::assertStringContainsString('/Helv', $built['content']);
    }

    public function testBuildWithNoCaptionHasNoTextOps(): void
    {
        $ap = new SignatureAppearance(0, 0.0, 0.0, 50.0, 20.0);
        $built = (new SignatureAppearanceBuilder())->build($ap);
        self::assertSame([0.0, 0.0, 50.0, 20.0], $built['bbox']);
        self::assertStringNotContainsString('Tj', $built['content']);
    }

    public function testParenthesesInCaptionAreEscaped(): void
    {
        $ap = new SignatureAppearance(0, 0.0, 0.0, 50.0, 20.0, 'A (B) C');
        $built = (new SignatureAppearanceBuilder())->build($ap);
        self::assertStringContainsString('\\(B\\)', $built['content']);
    }
}
