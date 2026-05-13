<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Unit;

$fixturesDir = __DIR__ . '/fixtures';
if (!is_dir($fixturesDir)) {
    mkdir($fixturesDir, 0755, true);
}

$zeros = fn (int $n): string => str_repeat("\x00", $n);

// Fixture 1: empty page without metadata (Phase 0 compat)
$doc = new Document(Unit::PT);
$doc->addPage();
$doc->save($fixturesDir . '/empty-page.pdf');
echo "Regenerated empty-page.pdf\n";

// Fixture 2: document with metadata (Phase 1a)
$doc = new Document(Unit::PT);
$doc->metadata()
    ->title('Test')
    ->author('User')
    ->subject('Phase 1a')
    ->keywords('phppdf, test')
    ->creator('Test Suite')
    ->creationDate(new DateTimeImmutable('2026-01-01T12:00:00+00:00'))
    ->documentId('abcdef0123456789abcdef0123456789');
$doc->addPage();
$doc->save($fixturesDir . '/document-with-metadata.pdf');
echo "Regenerated document-with-metadata.pdf\n";

// Fixture 3: encrypted document (Phase 1b)
$doc = new Document(Unit::PT);
$doc->metadata()
    ->title('Confidential')
    ->author('User')
    ->creationDate(new DateTimeImmutable('2026-01-01T12:00:00+00:00'))
    ->documentId('abcdef0123456789abcdef0123456789');
$doc->encryption()
    ->userPassword('user')
    ->ownerPassword('owner')
    ->allowPrint()
    ->allowCopy()
    ->withRandomSource($zeros);
$doc->addPage();
$doc->save($fixturesDir . '/encrypted-document.pdf');
echo "Regenerated encrypted-document.pdf\n";

// Fixture 4: page with graphics (Phase 2a)
$doc = new Document(Unit::PT);
$page = $doc->addPage();

$page->setStrokeColor(Color::hex('#ff0000'))
     ->setLineWidth(1)
     ->rect(20, 20, 100, 50)
     ->stroke();

$page->setFillColor(Color::rgb(0, 0, 255))
     ->circle(200, 200, 40)
     ->fill();

$page->setStrokeColor(Color::gray(128))
     ->setLineWidth(2)
     ->line(0, 0, 595, 842)
     ->stroke();

$page->setFillColor(Color::hex('#00aa00'))
     ->path()
     ->moveTo(300, 500)
     ->lineTo(400, 500)
     ->lineTo(350, 450)
     ->close()
     ->fill();

$page->save()
     ->translate(450, 100)
     ->rotate(30)
     ->setFillColor(Color::hex('#ff8800'))
     ->rect(-10, -10, 20, 20)
     ->fill();
$page->restore();

$doc->save($fixturesDir . '/page-with-graphics.pdf');
echo "Regenerated page-with-graphics.pdf\n";

// Fixture 5: page with text (Phase 2b)
$doc = new Document(Unit::PT);
$page = $doc->addPage();

$page->setFont(Font::helvetica()->bold(), 18);
$page->text(50, 50, 'Hello World');

$page->setFont(Font::times()->italic(), 12);
$page->text(50, 100, 'Résumé — café, naïveté, œuvre');

$page->setFont(Font::courier(), 10);
$page->text(50, 150, "Line 1\nLine 2\nLine 3");

$page->setFont(Font::helvetica(), 12);
$page->text(50, 190, 'Smørrebrød, skål, äpplen, Þórsdagur');

$page->setFont(Font::helvetica(), 14);
$page->text(50, 220, 'Prix : 19,99 €');

$doc->save($fixturesDir . '/page-with-text.pdf');
echo "Regenerated page-with-text.pdf\n";

// Fixture 6: page with cells (Phase 2c)
$doc = new Document(Unit::PT);
$page = $doc->addPage();
$page->setFont(Font::helvetica(), 12);

// Header centred, bordered, filled.
$page->cell(
    x: 50, y: 50, w: 300, h: 25,
    text: 'Invoice #2026-001',
    border: DragonOfMercy\PhpPdf\Border::all()->withWidth(0.8),
    fill: Color::rgb(242, 242, 242),
    align: DragonOfMercy\PhpPdf\TextAlign::CENTER,
    verticalAlign: DragonOfMercy\PhpPdf\VerticalAlign::MIDDLE,
);

// Wrapping prose with dashed border.
$result = $page->cell(
    x: 50, y: 80, w: 300,
    text: 'Long paragraph that wraps automatically across multiple lines depending on the available width.',
    border: DragonOfMercy\PhpPdf\Border::all()->withStyle(DragonOfMercy\PhpPdf\BorderStyle::DASHED),
);

// Right-aligned with a custom text color (no border).
$page->cell(
    x: 50, y: $result->y + 5, w: 300, h: 20,
    text: 'Total: 1234.56 EUR',
    textColor: Color::rgb(192, 0, 0),
    align: DragonOfMercy\PhpPdf\TextAlign::RIGHT,
    verticalAlign: DragonOfMercy\PhpPdf\VerticalAlign::MIDDLE,
);

// Condense fit.
$page->cell(
    x: 50, y: 200, w: 100, h: 20,
    text: 'Antidisestablishmentarianism',
    border: DragonOfMercy\PhpPdf\Border::all(),
    fit: DragonOfMercy\PhpPdf\Fit::CONDENSE,
    verticalAlign: DragonOfMercy\PhpPdf\VerticalAlign::MIDDLE,
);

// Shrink fit.
$page->cell(
    x: 200, y: 200, w: 100, h: 20,
    text: 'Antidisestablishmentarianism',
    border: DragonOfMercy\PhpPdf\Border::all(),
    fit: DragonOfMercy\PhpPdf\Fit::SHRINK,
    verticalAlign: DragonOfMercy\PhpPdf\VerticalAlign::MIDDLE,
);

// Partial dashed border (top/bottom only).
$page->cell(
    x: 50, y: 240, w: 300, h: 18,
    text: 'Top-and-bottom only',
    border: DragonOfMercy\PhpPdf\Border::sides(top: true, bottom: true)
        ->withStyle(DragonOfMercy\PhpPdf\BorderStyle::DASHED),
    align: DragonOfMercy\PhpPdf\TextAlign::CENTER,
    verticalAlign: DragonOfMercy\PhpPdf\VerticalAlign::MIDDLE,
);

// Dotted border.
$page->cell(
    x: 50, y: 270, w: 300, h: 18,
    text: 'Dotted',
    border: DragonOfMercy\PhpPdf\Border::all()->withStyle(DragonOfMercy\PhpPdf\BorderStyle::DOTTED)->withWidth(1.0),
    align: DragonOfMercy\PhpPdf\TextAlign::CENTER,
    verticalAlign: DragonOfMercy\PhpPdf\VerticalAlign::MIDDLE,
);

// Empty cell as decorative spacer.
$page->cell(
    x: 50, y: 300, w: 300, h: 8,
    text: '',
    border: DragonOfMercy\PhpPdf\Border::all(),
    fill: Color::rgb(220, 220, 220),
);

$doc->save($fixturesDir . '/page-with-cells.pdf');
echo "Regenerated page-with-cells.pdf\n";

// Fixture 7: page with images (Phase 4)
// Image bytes are loaded from tests/Golden/assets/ to keep the fixture
// stable across libjpeg/libpng versions (CI vs local). The palette PNG
// is built via TestImageFactory (deterministic byte-construction).
require_once __DIR__ . '/../Support/TestImageFactory.php';

$doc = new Document(Unit::PT);
$page = $doc->addPage();

$assetsDir = __DIR__ . '/assets';
$jpegImage = \DragonOfMercy\PhpPdf\Image::fromFile($assetsDir . '/jpeg-rgb-32x16.jpg');
$pngOpaqueImage = \DragonOfMercy\PhpPdf\Image::fromFile($assetsDir . '/png-opaque-rgb-24x12.png');
$pngAlphaImage = \DragonOfMercy\PhpPdf\Image::fromFile($assetsDir . '/png-alpha-rgba-16x16.png');
$paletteImage = \DragonOfMercy\PhpPdf\Image::fromBytes(
    \DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory::pngPalette(width: 8, height: 8),
);

$page->image($jpegImage, x: 50, y: 50, w: 200, h: 100);            // forced both
$page->image($pngOpaqueImage, x: 50, y: 200, w: 150);              // w-only (h derived)
$page->image($pngAlphaImage, x: 250, y: 200, w: 100, h: 100);      // forced both
$page->image($paletteImage, x: 400, y: 200);                        // intrinsic 8x8

$doc->save($fixturesDir . '/page-with-images.pdf');
echo "Regenerated page-with-images.pdf\n";

// Fixture 8: EAN-13 barcode (Phase 5)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\Ean13::of('9780131103627'),
    x: 20.0, y: 20.0, w: 50.0, h: 18.0,
);
$doc->save($fixturesDir . '/barcode-ean13.pdf');
echo "Regenerated barcode-ean13.pdf\n";

// Fixture 9: EAN-8 barcode (Phase 5)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\Ean8::of('73513537'),
    x: 20.0, y: 20.0, w: 30.0, h: 18.0,
);
$doc->save($fixturesDir . '/barcode-ean8.pdf');
echo "Regenerated barcode-ean8.pdf\n";

// Fixture 10: Code 128 barcode (Phase 5)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\Code128::of('SHIP-2026-001'),
    x: 20.0, y: 20.0, w: 70.0, h: 18.0,
);
$doc->save($fixturesDir . '/barcode-code128.pdf');
echo "Regenerated barcode-code128.pdf\n";

// Fixture 11: QR Code (Phase 5)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\QrCode::of('https://example.com'),
    x: 20.0, y: 20.0, w: 40.0,
);
$doc->save($fixturesDir . '/barcode-qr.pdf');
echo "Regenerated barcode-qr.pdf\n";

// Fixture 12: page with custom TTF (Phase 3a)
$fontsDir = $fixturesDir . '/fonts';
if (is_file($fontsDir . '/FreeSans.ttf') && is_file($fontsDir . '/FreeSansBold.ttf')) {
    $doc = new Document(Unit::PT);
    $doc->registerFontFamily(
        'FS',
        regular: $fontsDir . '/FreeSans.ttf',
        bold: $fontsDir . '/FreeSansBold.ttf',
    );

    $page = $doc->addPage();

    $page->setFont(Font::helvetica(), 11);
    $page->text(50, 50, 'Standard Helvetica baseline');

    $page->setFont(Font::custom('FS'), 14);
    $page->text(50, 80, 'Custom FreeSans regular');

    $page->setFont(Font::custom('FS')->bold(), 14);
    $page->text(50, 110, 'Custom FreeSans bold');

    $page->setFont(Font::custom('FS'), 12);
    $page->text(50, 140, 'Résumé café naïveté œuvre');

    $page->setFont(Font::custom('FS'), 12);
    $page->text(50, 170, 'α β γ δ ε ζ η θ');

    $page->setFont(Font::custom('FS')->bold(), 12);
    $page->text(50, 200, 'Москва Санкт-Петербург');

    $doc->save($fixturesDir . '/page-with-ttf.pdf');
    echo "Regenerated page-with-ttf.pdf\n";
} else {
    echo "Skipped page-with-ttf.pdf (FreeSans fixtures absent)\n";
}

// Fixture 13: page with header + footer + numbering (Phase 6)
$doc = new Document(Unit::PT);
$doc->setMargins(new \DragonOfMercy\PhpPdf\PageMargins(top: 80.0, right: 50.0, bottom: 60.0, left: 50.0));
$doc->setHeader(function (\DragonOfMercy\PhpPdf\Page $p): void {
    $p->setFont(Font::helvetica()->bold(), 14);
    $p->text(50, 40, 'Phase 6 Sample');
    $p->setLineWidth(0.5);
    $p->line(50, 65, 545, 65)->stroke();
});
$doc->setFooter(function (\DragonOfMercy\PhpPdf\Page $p, int $n, int $total): void {
    $p->setFont(Font::helvetica(), 9);
    $p->text(50, 800, "Page {$n} / {$total}");
});

$page = $doc->addPage();
$page->setFont(Font::helvetica(), 11);
$page->text(50, 100, 'Body content positioned below the header zone.');
$page->text(50, 120, 'Page numbering appears in the footer band.');

$doc->save($fixturesDir . '/page-with-header-footer.pdf');
echo "Regenerated page-with-header-footer.pdf\n";

// Fixture 14: page with auto-page-break (Phase 6)
$doc = new Document(Unit::PT);
$doc->setMargins(new \DragonOfMercy\PhpPdf\PageMargins(top: 60.0, right: 50.0, bottom: 60.0, left: 50.0));
$doc->setHeader(function (\DragonOfMercy\PhpPdf\Page $p): void {
    $p->setFont(Font::helvetica()->bold(), 11);
    $p->text(50, 35, 'Auto-break demo');
});
$doc->setFooter(function (\DragonOfMercy\PhpPdf\Page $p, int $n, int $total): void {
    $p->setFont(Font::helvetica(), 9);
    $p->text(50, 800, "Page {$n} / {$total}");
});
$doc->setAutoPageBreak(true);

$doc->addPage();
$doc->currentPage()->setFont(Font::helvetica(), 11);
for ($i = 1; $i <= 60; $i++) {
    $doc->currentPage()->cell(
        w: 495.0,
        h: 16.0,
        text: "Row {$i}",
        border: \DragonOfMercy\PhpPdf\Border::all(),
        ln: \DragonOfMercy\PhpPdf\NextPosition::NEWLINE,
    );
}

$doc->save($fixturesDir . '/page-auto-break.pdf');
echo "Regenerated page-auto-break.pdf\n";

// SVG golden fixtures (Phase 7)
$svgGoldens = [
    'svg-paths-only.pdf'        => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgPathsOnlyTest::class, 'buildPdfBytes'],
    'svg-shapes.pdf'            => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgShapesTest::class, 'buildPdfBytes'],
    'svg-transforms.pdf'        => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgTransformsTest::class, 'buildPdfBytes'],
    'svg-opacity.pdf'           => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgOpacityTest::class, 'buildPdfBytes'],
    'svg-fill-rule-evenodd.pdf' => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgFillRuleEvenoddTest::class, 'buildPdfBytes'],
    'svg-dasharray.pdf'         => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgDasharrayTest::class, 'buildPdfBytes'],
    'svg-use-defs.pdf'          => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgUseDefsTest::class, 'buildPdfBytes'],
    'svg-skip-unsupported.pdf'  => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgSkipUnsupportedTest::class, 'buildPdfBytes'],
    'svg-real-world-icon.pdf'   => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgRealWorldIconTest::class, 'buildPdfBytes'],
    'svg-multi-placement.pdf'   => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgMultiPlacementTest::class, 'buildPdfBytes'],
];

foreach ($svgGoldens as $name => [$class, $method]) {
    $bytes = $class::$method();
    file_put_contents($fixturesDir . '/' . $name, $bytes);
    echo "Regenerated {$name}\n";
}
