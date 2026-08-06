<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class PageWithCjkOtfTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/page/otf-cjk.pdf';
    private const string CJK_OTF = __DIR__ . '/assets/fonts/NotoSansCJKsc-Regular.otf';

    public function testPageWithCjkOtfMatchesFixtureBytes(): void
    {
        if (!is_file(self::CJK_OTF)) {
            self::markTestSkipped('Noto Sans CJK SC fixture absent');
        }
        $expected = file_get_contents(self::FIXTURE);
        self::assertIsString($expected, 'Golden fixture missing - regenerate with tests/Golden/regenerate.php');
        self::assertSame(
            $expected,
            $this->buildDocument()->output(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPageWithCjkOtfPassesQpdfCheck(): void
    {
        if (!is_file(self::CJK_OTF)) {
            self::markTestSkipped('Noto Sans CJK SC fixture absent');
        }
        Qpdf::assertCheck(self::FIXTURE);
    }

    public function testFixtureEmbedsOpenTypeCidFontType0(): void
    {
        if (!is_file(self::CJK_OTF)) {
            self::markTestSkipped('Noto Sans CJK SC fixture absent');
        }
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/Subtype /CIDFontType0', $bytes);
        self::assertStringContainsString('/Subtype /OpenType', $bytes);
    }

    public function testFixtureBaseFontHasSubsetPrefix(): void
    {
        if (!is_file(self::CJK_OTF)) {
            self::markTestSkipped('Noto Sans CJK SC fixture absent');
        }
        $bytes = $this->buildDocument()->output();
        self::assertMatchesRegularExpression('#/BaseFont /[A-Z]{6}\+#', $bytes);
    }

    public function testFontFile3StreamDropsHard(): void
    {
        if (!is_file(self::CJK_OTF)) {
            self::markTestSkipped('Noto Sans CJK SC fixture absent');
        }
        $bytes = $this->buildDocument()->output();
        $otfSize = filesize(self::CJK_OTF);
        self::assertIsInt($otfSize);
        // CJK whole-OTF is ~10-16 MB; with a handful of characters the resulting
        // PDF should be a tiny fraction of the OTF (conservative 15% bound).
        self::assertLessThan((int) ($otfSize * 0.15), strlen($bytes));
    }

    public function buildDocument(): Document
    {
        $doc = new Document(Unit::PT);
        $doc->registerFontFamily('Noto', regular: self::CJK_OTF);

        $page = $doc->addPage();

        $page->setFont(Font::helvetica(), 11);
        $page->text(50, 50, 'Phase 3c.1 CJK subsetting demo');

        $page->setFont(Font::custom('Noto'), 16);
        $page->text(50, 90, "\u{4E2D}\u{56FD} PDF \u{30C6}\u{30B9}\u{30C8} \u{D55C}\u{AE00}");

        return $doc;
    }
}
