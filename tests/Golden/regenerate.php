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
$doc->save($fixturesDir . '/doc/empty-page.pdf');
echo "Regenerated doc/empty-page.pdf\n";

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
$doc->save($fixturesDir . '/doc/document-with-metadata.pdf');
echo "Regenerated doc/document-with-metadata.pdf\n";

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
$doc->save($fixturesDir . '/doc/encrypted-document.pdf');
echo "Regenerated doc/encrypted-document.pdf\n";

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

$doc->save($fixturesDir . '/page/graphics.pdf');
echo "Regenerated page/graphics.pdf\n";

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

$doc->save($fixturesDir . '/page/text.pdf');
echo "Regenerated page/text.pdf\n";

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

$doc->save($fixturesDir . '/page/cells.pdf');
echo "Regenerated page/cells.pdf\n";

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

$doc->save($fixturesDir . '/page/images.pdf');
echo "Regenerated page/images.pdf\n";

// Fixture: image cursor flow (NextPosition on image())
$doc = new Document(Unit::PT);
$page = $doc->addPage();
$thumb = \DragonOfMercy\PhpPdf\Image::fromBytes(
    \DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory::pngPalette(width: 8, height: 8),
);
$page->setXY(50, 50);
// Two thumbnails side by side via the default RIGHT advance.
$page->image($thumb, w: 80, h: 60);                                          // at (50, 50); cursor -> (130, 50)
$page->image($thumb, w: 80, h: 60, ln: \DragonOfMercy\PhpPdf\NextPosition::NEWLINE); // at (130, 50); NEWLINE -> (50, 110)
// Third thumbnail on the new line, default RIGHT again.
$page->image($thumb, w: 80, h: 60);                                          // at (50, 110); cursor -> (130, 110)
$doc->save($fixturesDir . '/page/image-flow.pdf');
echo "Regenerated page/image-flow.pdf\n";

// Fixture 8: EAN-13 barcode (Phase 5)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\Ean13::of('9780131103627'),
    x: 20.0, y: 20.0, w: 50.0, h: 18.0,
);
$doc->save($fixturesDir . '/barcode/1d/ean13.pdf');
echo "Regenerated barcode/1d/ean13.pdf\n";

// Fixture 9: EAN-8 barcode (Phase 5)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\Ean8::of('73513537'),
    x: 20.0, y: 20.0, w: 30.0, h: 18.0,
);
$doc->save($fixturesDir . '/barcode/1d/ean8.pdf');
echo "Regenerated barcode/1d/ean8.pdf\n";

// Fixture 10: Code 128 barcode (Phase 5)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\Code128::of('SHIP-2026-001'),
    x: 20.0, y: 20.0, w: 70.0, h: 18.0,
);
$doc->save($fixturesDir . '/barcode/1d/code128.pdf');
echo "Regenerated barcode/1d/code128.pdf\n";

// Fixture: Code 128 vertical (1D orientation)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\Code128::of('SHIP-2026-001')->vertical(),
    x: 20.0, y: 20.0, w: 70.0, h: 18.0,
);
$doc->save($fixturesDir . '/barcode/1d/code128-vertical.pdf');
echo "Regenerated barcode/1d/code128-vertical.pdf\n";

// Fixture: EAN-13 vertical (1D orientation)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\Ean13::of('9780131103627')->vertical(),
    x: 20.0, y: 20.0, w: 60.0, h: 25.0,
);
$doc->save($fixturesDir . '/barcode/1d/ean13-vertical.pdf');
echo "Regenerated barcode/1d/ean13-vertical.pdf\n";

// Fixture: ITF vertical (1D orientation)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\Itf::of('1234567890')->vertical(),
    x: 20.0, y: 20.0, w: 60.0, h: 20.0,
);
$doc->save($fixturesDir . '/barcode/1d/itf-vertical.pdf');
echo "Regenerated barcode/1d/itf-vertical.pdf\n";

// Fixture: UPC-A vertical (1D orientation)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\Upca::of('03600029145')->vertical(),
    x: 20.0, y: 20.0, w: 45.0, h: 22.0,
);
$doc->save($fixturesDir . '/barcode/1d/upca-vertical.pdf');
echo "Regenerated barcode/1d/upca-vertical.pdf\n";

// Fixture 11: QR Code (Phase 5)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\QrCode::of('https://example.com'),
    x: 20.0, y: 20.0, w: 40.0,
);
$doc->save($fixturesDir . '/barcode/2d/qr.pdf');
echo "Regenerated barcode/2d/qr.pdf\n";

// Fixture: QR V15-M with URL payload (Phase 5 follow-up)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$urlPattern = 'https://example.com/orders/2026-05-15?session=abc123&token=';
$v15Payload = substr(str_repeat($urlPattern, 10), 0, 400);
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\QrCode::of($v15Payload),
    x: 20.0, y: 20.0, w: 60.0,
);
$doc->save($fixturesDir . '/barcode/2d/qr-v15.pdf');
echo "Regenerated barcode/2d/qr-v15.pdf\n";

// Fixture: QR V25-M with JSON payload (Phase 5 follow-up; exercises remainderBits=4 band)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$itemRow = '{"sku":"PROD-12345","qty":3,"price":29.95},';
$v25Payload = '{"order":"ORD-2026-05-15-0001","items":[' . substr(str_repeat($itemRow, 22), 0, -1) . ']}';
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\QrCode::of($v25Payload),
    x: 20.0, y: 20.0, w: 80.0,
);
$doc->save($fixturesDir . '/barcode/2d/qr-v25.pdf');
echo "Regenerated barcode/2d/qr-v25.pdf\n";

// Fixture: QR V40-L at near-capacity (Phase 5 follow-up; stress test the upper bound)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$lorem = 'Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua Ut enim ad minim veniam quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat ';
$v40Payload = substr(str_repeat($lorem, 13), 0, 2950);
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\QrCode::of($v40Payload, \DragonOfMercy\PhpPdf\Barcode\ErrorCorrection::L),
    x: 20.0, y: 20.0, w: 120.0,
);
$doc->save($fixturesDir . '/barcode/2d/qr-v40.pdf');
echo "Regenerated barcode/2d/qr-v40.pdf\n";

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

    $doc->save($fixturesDir . '/page/ttf.pdf');
    echo "Regenerated page/ttf.pdf\n";
} else {
    echo "Skipped page/ttf.pdf (FreeSans fixtures absent)\n";
}

// Fixture: page with custom OTF/CFF (Phase 3c)
if (is_file($fontsDir . '/IBMPlexSans-Regular.otf') && is_file($fontsDir . '/IBMPlexSans-Bold.otf')) {
    $doc = new Document(Unit::PT);
    $doc->registerFontFamily(
        'Plex',
        regular: $fontsDir . '/IBMPlexSans-Regular.otf',
        bold: $fontsDir . '/IBMPlexSans-Bold.otf',
    );

    $page = $doc->addPage();

    $page->setFont(Font::helvetica(), 11);
    $page->text(50, 50, 'Standard Helvetica baseline');

    $page->setFont(Font::custom('Plex'), 14);
    $page->text(50, 80, 'Custom IBM Plex Sans regular');

    $page->setFont(Font::custom('Plex')->bold(), 14);
    $page->text(50, 110, 'Custom IBM Plex Sans bold');

    $page->setFont(Font::custom('Plex'), 12);
    $page->text(50, 140, 'Resume cafe naivete oeuvre');

    $doc->save($fixturesDir . '/page/otf.pdf');
    echo "Regenerated page/otf.pdf\n";
} else {
    echo "Skipped page/otf.pdf (IBM Plex Sans OTF fixtures absent)\n";
}

// Fixture: page with custom OTF/CFF CID-keyed (Phase 3c.1)
if (is_file($fontsDir . '/NotoSansCJKsc-Regular.otf')) {
    $doc = new Document(Unit::PT);
    $doc->registerFontFamily('Noto', regular: $fontsDir . '/NotoSansCJKsc-Regular.otf');

    $page = $doc->addPage();

    $page->setFont(Font::helvetica(), 11);
    $page->text(50, 50, 'Phase 3c.1 CJK subsetting demo');

    $page->setFont(Font::custom('Noto'), 16);
    $page->text(50, 90, "\u{4E2D}\u{56FD} PDF \u{30C6}\u{30B9}\u{30C8} \u{D55C}\u{AE00}");

    $doc->save($fixturesDir . '/page/otf-cjk.pdf');
    $cjkSize = filesize($fixturesDir . '/page/otf-cjk.pdf');
    $otfSize = filesize($fontsDir . '/NotoSansCJKsc-Regular.otf');
    if ($cjkSize !== false && $otfSize !== false) {
        echo sprintf(
            "Regenerated page/otf-cjk.pdf (%d bytes, %.2f%% of %d-byte source OTF)\n",
            $cjkSize,
            ($cjkSize / $otfSize) * 100,
            $otfSize,
        );
    } else {
        echo "Regenerated page/otf-cjk.pdf\n";
    }
} else {
    echo "Skipped page/otf-cjk.pdf (Noto Sans CJK SC fixture absent)\n";
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

$doc->save($fixturesDir . '/page/header-footer.pdf');
echo "Regenerated page/header-footer.pdf\n";

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
$doc->getCurrentPage()->setFont(Font::helvetica(), 11);
for ($i = 1; $i <= 60; $i++) {
    $doc->getCurrentPage()->cell(
        w: 495.0,
        h: 16.0,
        text: "Row {$i}",
        border: \DragonOfMercy\PhpPdf\Border::all(),
        ln: \DragonOfMercy\PhpPdf\NextPosition::NEWLINE,
    );
}

$doc->save($fixturesDir . '/page/auto-break.pdf');
echo "Regenerated page/auto-break.pdf\n";

// SVG golden fixtures (Phase 7)
$svgGoldens = [
    'svg/basic/paths-only.pdf'        => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgPathsOnlyTest::class, 'buildPdfBytes'],
    'svg/basic/shapes.pdf'            => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgShapesTest::class, 'buildPdfBytes'],
    'svg/basic/transforms.pdf'        => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgTransformsTest::class, 'buildPdfBytes'],
    'svg/basic/opacity.pdf'           => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgOpacityTest::class, 'buildPdfBytes'],
    'svg/basic/fill-rule-evenodd.pdf' => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgFillRuleEvenoddTest::class, 'buildPdfBytes'],
    'svg/basic/dasharray.pdf'         => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgDasharrayTest::class, 'buildPdfBytes'],
    'svg/basic/use-defs.pdf'          => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgUseDefsTest::class, 'buildPdfBytes'],
    'svg/basic/skip-unsupported.pdf'      => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgSkipUnsupportedTest::class, 'buildPdfBytes'],
    'svg/basic/real-world-icon.pdf'       => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgRealWorldIconTest::class, 'buildPdfBytes'],
    'svg/basic/multi-placement.pdf'       => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgMultiPlacementTest::class, 'buildPdfBytes'],
    'svg/gradient/linear.pdf'       => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgGradientLinearTest::class, 'buildPdfBytes'],
    'svg/gradient/radial.pdf'       => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgGradientRadialTest::class, 'buildPdfBytes'],
    'svg/gradient/userspace.pdf'    => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgGradientUserspaceTest::class, 'buildPdfBytes'],
    'svg/gradient/href-inherit.pdf' => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgGradientHrefInheritTest::class, 'buildPdfBytes'],
    'svg/gradient/multistop.pdf'    => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgGradientMultistopTest::class, 'buildPdfBytes'],
    'svg/gradient/on-stroke.pdf'    => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgGradientOnStrokeTest::class, 'buildPdfBytes'],
    'svg/gradient/opacity.pdf'      => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgGradientOpacityTest::class, 'buildPdfBytes'],
    'svg/gradient/linear-repeat.pdf'     => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgGradientLinearRepeatTest::class, 'buildPdfBytes'],
    'svg/gradient/linear-reflect.pdf'    => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgGradientLinearReflectTest::class, 'buildPdfBytes'],
    'svg/gradient/radial-repeat.pdf'     => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgGradientRadialRepeatTest::class, 'buildPdfBytes'],
    'svg/gradient/radial-reflect.pdf'    => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgGradientRadialReflectTest::class, 'buildPdfBytes'],
    'svg/image/png.pdf'                  => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgImagePngTest::class, 'buildPdfBytes'],
    'svg/image/jpeg.pdf'                 => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgImageJpegTest::class, 'buildPdfBytes'],
    'svg/image/png-alpha.pdf'            => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgImagePngAlphaTest::class, 'buildPdfBytes'],
    'svg/image/aspect-meet.pdf'          => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgImageAspectMeetTest::class, 'buildPdfBytes'],
    'svg/image/aspect-slice.pdf'         => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgImageAspectSliceTest::class, 'buildPdfBytes'],
    'svg/image/opacity-transform.pdf'    => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgImageOpacityTransformTest::class, 'buildPdfBytes'],
    'svg/image/dedup.pdf'                => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgImageDedupTest::class, 'buildPdfBytes'],
    'svg/text/simple.pdf'             => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgTextSimpleTest::class, 'buildPdfBytes'],
    'svg/text/bold-italic.pdf'        => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgTextBoldItalicTest::class, 'buildPdfBytes'],
    'svg/text/anchor.pdf'             => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgTextAnchorTest::class, 'buildPdfBytes'],
    'svg/text/multiline.pdf'          => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgTextMultilineTest::class, 'buildPdfBytes'],
    'svg/text/stroke.pdf'             => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgTextStrokeTest::class, 'buildPdfBytes'],
    'svg/text/opacity-transform.pdf'  => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgTextOpacityTransformTest::class, 'buildPdfBytes'],
    'svg/text/textpath-line.pdf'         => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgTextPathLineTest::class, 'buildPdfBytes'],
    'svg/text/textpath-curve.pdf'        => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgTextPathCurveTest::class, 'buildPdfBytes'],
    'svg/text/textpath-middle.pdf'       => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgTextPathMiddleTest::class, 'buildPdfBytes'],
    'svg/text/textpath-tspan-colors.pdf' => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgTextPathTspanColorsTest::class, 'buildPdfBytes'],
    'svg/css/type-selector.pdf'  => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgCssTypeSelectorTest::class, 'buildPdfBytes'],
    'svg/css/class-override.pdf' => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgCssClassOverrideTest::class, 'buildPdfBytes'],
    'svg/css/specificity.pdf'    => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgCssSpecificityTest::class, 'buildPdfBytes'],
    'svg/css/inline-wins.pdf'    => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgCssInlineWinsTest::class, 'buildPdfBytes'],
    'svg/css/text-class.pdf'     => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgCssTextClassTest::class, 'buildPdfBytes'],
    'svg/css/root-inherit.pdf'   => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgCssRootInheritTest::class, 'buildPdfBytes'],
    'svg/clip/basic.pdf'         => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgClipBasicTest::class, 'buildPdfBytes'],
    'svg/clip/group.pdf'         => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgClipGroupTest::class, 'buildPdfBytes'],
    'svg/clip/objectbbox.pdf'    => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgClipObjectBBoxTest::class, 'buildPdfBytes'],
    'svg/clip/union.pdf'         => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgClipUnionTest::class, 'buildPdfBytes'],
    'svg/clip/evenodd.pdf'       => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgClipEvenoddTest::class, 'buildPdfBytes'],
    'svg/clip/css.pdf'           => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgClipCssTest::class, 'buildPdfBytes'],
    'svg/clip/use.pdf'           => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgClipUseTest::class, 'buildPdfBytes'],
    'svg/pattern/basic.pdf'      => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgPatternBasicTest::class, 'buildPdfBytes'],
    'svg/pattern/objectbbox.pdf' => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgPatternObjectBBoxTest::class, 'buildPdfBytes'],
    'svg/pattern/transform.pdf'  => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgPatternTransformTest::class, 'buildPdfBytes'],
    'svg/pattern/viewbox.pdf'    => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgPatternViewBoxTest::class, 'buildPdfBytes'],
    'svg/symbol/basic.pdf'           => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgSymbolBasicTest::class, 'buildPdfBytes'],
    'svg/symbol/preserve-aspect.pdf' => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgSymbolPreserveAspectTest::class, 'buildPdfBytes'],
    'svg/marker/line.pdf'            => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgMarkerLineTest::class, 'buildPdfBytes'],
    'svg/marker/polyline-auto.pdf'   => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgMarkerPolylineAutoTest::class, 'buildPdfBytes'],
    'svg/marker/strokewidth.pdf'     => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgMarkerStrokeWidthTest::class, 'buildPdfBytes'],
    'svg/marker/path-bezier.pdf'     => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgMarkerPathBezierTest::class, 'buildPdfBytes'],
    'svg/mask/basic.pdf'             => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgMaskBasicTest::class, 'buildPdfBytes'],
    'svg/mask/text.pdf'              => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgMaskTextTest::class, 'buildPdfBytes'],
    'svg/mask/userspace.pdf'         => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgMaskUserspaceTest::class, 'buildPdfBytes'],
    'svg/mask/group.pdf'             => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgMaskGroupTest::class, 'buildPdfBytes'],
    'svg/mask/bbox-content.pdf'      => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgMaskBBoxContentTest::class, 'buildPdfBytes'],
    'svg/gradient/stop-alpha-linear.pdf'    => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgGradientStopAlphaLinearTest::class, 'buildPdfBytes'],
    'svg/gradient/stop-alpha-radial.pdf'    => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgGradientStopAlphaRadialTest::class, 'buildPdfBytes'],
    'svg/gradient/stop-alpha-multistop.pdf' => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgGradientStopAlphaMultistopTest::class, 'buildPdfBytes'],
    'svg/gradient/stop-alpha-stroke.pdf'    => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgGradientStopAlphaStrokeTest::class, 'buildPdfBytes'],
    'svg/filter/blur.pdf'                   => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgFilterBlurTest::class, 'buildPdfBytes'],
    'svg/filter/drop-shadow.pdf'            => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgFilterDropShadowTest::class, 'buildPdfBytes'],
    'svg/filter/color-matrix-saturate.pdf'  => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgFilterColorMatrixSaturateTest::class, 'buildPdfBytes'],
    'svg/filter/offset-merge.pdf'           => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgFilterOffsetMergeTest::class, 'buildPdfBytes'],
    'svg/filter/composite-arithmetic.pdf'   => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgFilterCompositeArithmeticTest::class, 'buildPdfBytes'],
    'svg/filter/flood.pdf'                  => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgFilterFloodTest::class, 'buildPdfBytes'],
    'svg/filter/blend-multiply.pdf'         => [\DragonOfMercy\PhpPdf\Tests\Golden\SvgFilterBlendMultiplyTest::class, 'buildPdfBytes'],
];

foreach ($svgGoldens as $name => [$class, $method]) {
    $bytes = $class::$method();
    file_put_contents($fixturesDir . '/' . $name, $bytes);
    echo "Regenerated {$name}\n";
}

// SVG text with custom registered fonts (guarded on font assets being present).
$svgCustomFontGoldens = [
    'svg/text/custom-ttf.pdf'              => \DragonOfMercy\PhpPdf\Tests\Golden\SvgTextCustomTtfTest::class,
    'svg/text/custom-variant-fallback.pdf' => \DragonOfMercy\PhpPdf\Tests\Golden\SvgTextCustomVariantFallbackTest::class,
    'svg/text/custom-anchor-stroke.pdf'    => \DragonOfMercy\PhpPdf\Tests\Golden\SvgTextCustomAnchorStrokeTest::class,
];
foreach ($svgCustomFontGoldens as $name => $class) {
    if (!$class::fontsPresent()) {
        echo "Skipped {$name} (font assets absent)\n";
        continue;
    }
    $path = $fixturesDir . '/' . $name;
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $class::buildPdfBytes());
    echo "Regenerated {$name}\n";
}

// Fixture: UPC-A barcode (extra 1D pack)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\Upca::of('03600029145'),
    x: 20.0, y: 20.0, w: 45.0, h: 22.0,
);
$doc->save($fixturesDir . '/barcode/1d/upca.pdf');
echo "Regenerated barcode/1d/upca.pdf\n";

// Fixture: Code 39 barcode (extra 1D pack)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\Code39::of('CODE 39'),
    x: 20.0, y: 20.0, w: 90.0, h: 20.0,
);
$doc->save($fixturesDir . '/barcode/1d/code39.pdf');
echo "Regenerated barcode/1d/code39.pdf\n";

// Fixture: Code 93 barcode (extra 1D pack)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\Code93::of('TEST93'),
    x: 20.0, y: 20.0, w: 80.0, h: 20.0,
);
$doc->save($fixturesDir . '/barcode/1d/code93.pdf');
echo "Regenerated barcode/1d/code93.pdf\n";

// Fixture: ITF barcode (extra 1D pack)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\Itf::of('12345670'),
    x: 20.0, y: 20.0, w: 60.0, h: 20.0,
);
$doc->save($fixturesDir . '/barcode/1d/itf.pdf');
echo "Regenerated barcode/1d/itf.pdf\n";

// Fixture: ITF barcode with GS1 full-frame bearer bar
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\Itf::ofGtin14('1234567890123')->withBearerBar(),
    x: 20.0, y: 20.0, w: 70.0, h: 22.0,
);
$doc->save($fixturesDir . '/barcode/1d/itf-bearer.pdf');
echo "Regenerated barcode/1d/itf-bearer.pdf\n";

// Fixture: page with outlines + hyperlinks (Phase 7)
$doc = new Document(Unit::PT);

$page1 = $doc->addPage();
$page1->setFont(Font::helvetica()->bold(), 18);
$page1->text(50, 60, 'Chapter 1');
$page1->setFont(Font::helvetica(), 11);
$page1->text(50, 100, 'Visit https://example.com for the project home page.');
$page1->link(50, 90, 200, 14, \DragonOfMercy\PhpPdf\Outline\Link::url('https://example.com'));
$page1->text(50, 140, 'Jump to Chapter 3.');
$page1->link(50, 130, 200, 14, \DragonOfMercy\PhpPdf\Outline\Link::destination(\DragonOfMercy\PhpPdf\Outline\Destination::page(2)));

$page2 = $doc->addPage();
$page2->setFont(Font::helvetica()->bold(), 18);
$page2->text(50, 60, 'Chapter 2');
$page2->setFont(Font::helvetica(), 11);
$page2->text(50, 100, 'See Wikipedia for the PDF spec.');
$page2->link(50, 90, 200, 14, \DragonOfMercy\PhpPdf\Outline\Link::url('https://en.wikipedia.org/wiki/PDF'));
$page2->text(50, 140, 'Back to Chapter 1.');
$page2->link(50, 130, 200, 14, \DragonOfMercy\PhpPdf\Outline\Link::destination(\DragonOfMercy\PhpPdf\Outline\Destination::page(0)));

$page3 = $doc->addPage();
$page3->setFont(Font::helvetica()->bold(), 18);
$page3->text(50, 60, 'Chapter 3');
$page3->setFont(Font::helvetica(), 11);
$page3->text(50, 100, 'Email the maintainer.');
$page3->link(50, 90, 200, 14, \DragonOfMercy\PhpPdf\Outline\Link::url('mailto:test@example.com'));

$root = $doc->outline();
$chap1 = $root->add('Chapter 1', \DragonOfMercy\PhpPdf\Outline\Destination::page(0));
$chap1->add('Section 1.1', \DragonOfMercy\PhpPdf\Outline\Destination::page(0));
$chap1->add('Section 1.2', \DragonOfMercy\PhpPdf\Outline\Destination::page(0));
$chap2 = $root->add('Chapter 2', \DragonOfMercy\PhpPdf\Outline\Destination::page(1));
$chap2->add('Section 2.1', \DragonOfMercy\PhpPdf\Outline\Destination::page(1));
$root->add('Chapter 3', \DragonOfMercy\PhpPdf\Outline\Destination::page(2));

$doc->save($fixturesDir . '/page/outlines-and-links.pdf');
echo "Regenerated page/outlines-and-links.pdf\n";

// Fixture: page with all 5 AcroForm field types (Phase 8)
$doc = (new \DragonOfMercy\PhpPdf\Tests\Golden\PageWithFormsTest('test'))->buildDocument();
$doc->save($fixturesDir . '/page/forms.pdf');
echo "Regenerated page/forms.pdf\n";

// Fixture: page with styled AcroForm fields (Phase 8.1)
$doc = (new \DragonOfMercy\PhpPdf\Tests\Golden\PageWithStyledFormsTest('test'))->buildDocument();
$doc->save($fixturesDir . '/page/styled-forms.pdf');
echo "Regenerated page/styled-forms.pdf\n";

// Fixture: push-button fields (resetForm + openUrl actions)
$doc = (new \DragonOfMercy\PhpPdf\Tests\Golden\FormPushButtonTest('test'))->buildDocument();
$doc->save($fixturesDir . '/form/pushbutton.pdf');
echo "Regenerated form/pushbutton.pdf\n";

// Fixture: password text field
$doc = (new \DragonOfMercy\PhpPdf\Tests\Golden\FormPasswordTest('test'))->buildDocument();
$doc->save($fixturesDir . '/form/password.pdf');
echo "Regenerated form/password.pdf\n";

// Fixture: form with JavaScript actions (Format/Calculate/Validate/FieldActions + document script)
$doc = (new \DragonOfMercy\PhpPdf\Tests\Golden\FormJavascriptTest('test'))->buildDocument();
$doc->save($fixturesDir . '/form/javascript.pdf');
echo "Regenerated form/javascript.pdf\n";

// Fixture: SubmitForm button action (HTML format)
$doc = (new \DragonOfMercy\PhpPdf\Tests\Golden\FormSubmitTest('test'))->buildDocument();
$doc->save($fixturesDir . '/form/submit.pdf');
echo "Regenerated form/submit.pdf\n";

// Fixture: signature fields (visible + invisible placeholder, /SigFlags)
$doc = (new \DragonOfMercy\PhpPdf\Tests\Golden\FormSignatureTest('test'))->buildDocument();
$doc->save($fixturesDir . '/form/signature.pdf');
echo "Regenerated form/signature.pdf\n";

// Fixture: form polish (hidden/noExport flags, /BS border style, decoupled defaultValue, tab order)
$doc = (new \DragonOfMercy\PhpPdf\Tests\Golden\FormPolishTest('test'))->buildDocument();
$doc->save($fixturesDir . '/form/polish.pdf');
echo "Regenerated form/polish.pdf\n";

// Fixture: linked form fields (shared name -> parent /Kids widgets)
$doc = (new \DragonOfMercy\PhpPdf\Tests\Golden\FormLinkingTest('test'))->buildDocument();
$doc->save($fixturesDir . '/form/linking.pdf');
echo "Regenerated form/linking.pdf\n";

// Fixture: Aztec Code - short ASCII URL, MEDIUM EC, default color
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\AztecCode::of('https://example.com'),
    x: 20.0, y: 20.0, w: 40.0,
);
$doc->save($fixturesDir . '/barcode/2d/aztec.pdf');
echo "Regenerated barcode/2d/aztec.pdf\n";

// Fixture: Aztec Code - compact max (~72 chars), MEDIUM EC
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\AztecCode::of(str_repeat('HELLO ', 12)),
    x: 20.0, y: 20.0, w: 50.0,
);
$doc->save($fixturesDir . '/barcode/2d/aztec-compact-max.pdf');
echo "Regenerated barcode/2d/aztec-compact-max.pdf\n";

// Fixture: Aztec Code - boarding-pass payload, MEDIUM EC, Full Range
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\AztecCode::of('M1DOE/JOHN       EABCDEF DTWJFKAA 1234 123Y012C0001 100'),
    x: 20.0, y: 20.0, w: 50.0,
);
$doc->save($fixturesDir . '/barcode/2d/aztec-full-mid.pdf');
echo "Regenerated barcode/2d/aztec-full-mid.pdf\n";

// Fixture: Aztec Code - 200 x 'A', LOW EC, Full Range high-layer
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\AztecCode::of(str_repeat('A', 200), \DragonOfMercy\PhpPdf\Barcode\AztecEc::LOW),
    x: 20.0, y: 20.0, w: 80.0,
);
$doc->save($fixturesDir . '/barcode/2d/aztec-full-max.pdf');
echo "Regenerated barcode/2d/aztec-full-max.pdf\n";

// Fixture: Aztec Code - SHORT, HIGH EC, custom color
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\AztecCode::of('SHORT', \DragonOfMercy\PhpPdf\Barcode\AztecEc::HIGH)->withColor(Color::rgb(192, 0, 0)),
    x: 20.0, y: 20.0, w: 30.0,
);
$doc->save($fixturesDir . '/barcode/2d/aztec-ec-high.pdf');
echo "Regenerated barcode/2d/aztec-ec-high.pdf\n";

// Fixture: Aztec Code - UTF-8 e-acute (ECI path), MEDIUM EC
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\AztecCode::of("caf\xc3\xa9"),
    x: 20.0, y: 20.0, w: 30.0,
);
$doc->save($fixturesDir . '/barcode/2d/aztec-unicode.pdf');
echo "Regenerated barcode/2d/aztec-unicode.pdf\n";

// Fixture: DataMatrix - smallest square ('Hello' in 10x10 or 12x12)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\DataMatrix::of('Hello'),
    x: 20.0, y: 20.0, w: 25.0,
);
$doc->save($fixturesDir . '/barcode/2d/datamatrix.pdf');
echo "Regenerated barcode/2d/datamatrix.pdf\n";

// Fixture: DataMatrix - digit-pair packing
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\DataMatrix::of('1234567890'),
    x: 20.0, y: 20.0, w: 25.0,
);
$doc->save($fixturesDir . '/barcode/2d/datamatrix-digits.pdf');
echo "Regenerated barcode/2d/datamatrix-digits.pdf\n";

// Fixture: DataMatrix - C40 latch for uppercase + digits
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\DataMatrix::of('PARTNO ABCDEFGHIJ 1234567890 REV3'),
    x: 20.0, y: 20.0, w: 30.0,
);
$doc->save($fixturesDir . '/barcode/2d/datamatrix-c40.pdf');
echo "Regenerated barcode/2d/datamatrix-c40.pdf\n";

// Fixture: DataMatrix - Text latch for lowercase
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\DataMatrix::of('the quick brown fox jumps over the lazy dog'),
    x: 20.0, y: 20.0, w: 30.0,
);
$doc->save($fixturesDir . '/barcode/2d/datamatrix-text.pdf');
echo "Regenerated barcode/2d/datamatrix-text.pdf\n";

// Fixture: DataMatrix - Base256 path for UTF-8 input
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\DataMatrix::of("caf\xC3\xA9 \xC3\xA0 la fran\xC3\xA7aise"),
    x: 20.0, y: 20.0, w: 30.0,
);
$doc->save($fixturesDir . '/barcode/2d/datamatrix-unicode.pdf');
echo "Regenerated barcode/2d/datamatrix-unicode.pdf\n";

// Fixture: DataMatrix - multi-region symbol (around 52x52)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\DataMatrix::of(str_repeat('ABCDEFGHIJ', 18)),
    x: 20.0, y: 20.0, w: 60.0,
);
$doc->save($fixturesDir . '/barcode/2d/datamatrix-large.pdf');
echo "Regenerated barcode/2d/datamatrix-large.pdf\n";

// Fixture: DataMatrix - large multi-region symbol pushing toward 144x144
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(
    \DragonOfMercy\PhpPdf\Barcode\DataMatrix::of(str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 25)),
    x: 20.0, y: 20.0, w: 80.0,
);
$doc->save($fixturesDir . '/barcode/2d/datamatrix-max.pdf');
echo "Regenerated barcode/2d/datamatrix-max.pdf\n";

// Fixture: PDF417 - short ASCII
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(\DragonOfMercy\PhpPdf\Barcode\Pdf417::of('PDF417 sample 12345'), x: 20.0, y: 20.0, w: 90.0);
$doc->save($fixturesDir . '/barcode/2d/pdf417.pdf');
echo "Regenerated barcode/2d/pdf417.pdf\n";

// Fixture: PDF417 - long numeric (Numeric compaction)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(\DragonOfMercy\PhpPdf\Barcode\Pdf417::of('0123456789012345678901234567890123456789'), x: 20.0, y: 20.0, w: 90.0);
$doc->save($fixturesDir . '/barcode/2d/pdf417-numeric.pdf');
echo "Regenerated barcode/2d/pdf417-numeric.pdf\n";

// Fixture: PDF417 - column-constrained (6 data columns)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(\DragonOfMercy\PhpPdf\Barcode\Pdf417::of('Tracking PKG-2026-0001 zone A')->withColumns(6), x: 20.0, y: 20.0, w: 90.0);
$doc->save($fixturesDir . '/barcode/2d/pdf417-columns.pdf');
echo "Regenerated barcode/2d/pdf417-columns.pdf\n";

// Fixture: PDF417 - high error-correction level (6)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(\DragonOfMercy\PhpPdf\Barcode\Pdf417::of('High EC sample')->withErrorCorrection(6), x: 20.0, y: 20.0, w: 90.0);
$doc->save($fixturesDir . '/barcode/2d/pdf417-ec.pdf');
echo "Regenerated barcode/2d/pdf417-ec.pdf\n";

// Fixture: PDF417 - UTF-8 payload (ECI 26)
$doc = new Document(Unit::MM);
$page = $doc->addPage();
$page->barcode(\DragonOfMercy\PhpPdf\Barcode\Pdf417::of("Colis caf\xC3\xA9 na\xC3\xAFvet\xC3\xA9"), x: 20.0, y: 20.0, w: 90.0);
$doc->save($fixturesDir . '/barcode/2d/pdf417-unicode.pdf');
echo "Regenerated barcode/2d/pdf417-unicode.pdf\n";

// Fixture: Barcode Gallery - every supported format on a single A4 page
$doc = new Document(Unit::MM);
$doc->setMargins(\DragonOfMercy\PhpPdf\PageMargins::sides(top: 20, right: 20, bottom: 40, left: 20));
$page = $doc->addPage();

$page->setFont(Font::helvetica()->bold(), 16);
$page->text(20, 22, 'phppdf - Barcode Gallery');
$page->setFont(Font::helvetica(), 9);
$page->text(20, 28, 'All supported 1D and 2D barcode formats in a single page.');

$labelX = 20.0;
$codeX = 70.0;
$dataX = 140.0;
$row1dHeight = 18.0;
$row1dBarcodeWidth = 60.0;
$row1dBarcodeHeight = 10.0;
$y = 40.0;

$page->setFont(Font::helvetica()->bold(), 9);
$page->text($labelX, $y - 3, 'Format');
$page->text($codeX, $y - 3, 'Barcode');
$page->text($dataX, $y - 3, 'Encoded data');

$rows1d = [
    ['EAN-13', \DragonOfMercy\PhpPdf\Barcode\Ean13::of('978013110362'), '978013110362'],
    ['EAN-8', \DragonOfMercy\PhpPdf\Barcode\Ean8::of('1234567'), '1234567'],
    ['Code 128', \DragonOfMercy\PhpPdf\Barcode\Code128::of('SHIP-2026-001'), 'SHIP-2026-001'],
    ['UPC-A', \DragonOfMercy\PhpPdf\Barcode\Upca::of('03600029145'), '03600029145'],
    ['Code 39', \DragonOfMercy\PhpPdf\Barcode\Code39::of('CODE 39 ABC'), 'CODE 39 ABC'],
    ['Code 93', \DragonOfMercy\PhpPdf\Barcode\Code93::of('CODE-93-XYZ'), 'CODE-93-XYZ'],
    ['ITF', \DragonOfMercy\PhpPdf\Barcode\Itf::of('1234567890'), '1234567890'],
];

foreach ($rows1d as [$label, $code, $data]) {
    $page->setFont(Font::helvetica()->bold(), 10);
    $page->text($labelX, $y + 6, $label);
    $page->barcode($code, x: $codeX, y: $y, w: $row1dBarcodeWidth, h: $row1dBarcodeHeight);
    $page->setFont(Font::courier(), 9);
    $page->text($dataX, $y + 6, $data);
    $y += $row1dHeight;
}

$y += 6.0;
$page->setFont(Font::helvetica()->bold(), 10);
$page->text($labelX, $y, '2D codes');
$y += 6.0;

$rows2d = [
    ['QR Code (M)', \DragonOfMercy\PhpPdf\Barcode\QrCode::of('https://example.com/product/SKU-2026')],
    ['QR Code (H)', \DragonOfMercy\PhpPdf\Barcode\QrCode::of('phppdf', \DragonOfMercy\PhpPdf\Barcode\ErrorCorrection::H)],
    ['Aztec',       \DragonOfMercy\PhpPdf\Barcode\AztecCode::of('https://example.com')],
    ['DataMatrix',  \DragonOfMercy\PhpPdf\Barcode\DataMatrix::of('https://example.com/dm')],
];

$code2dSize = 28.0;
$col2dX = [$labelX, 65.0, 110.0, 155.0];

foreach ($rows2d as $i => [$label, $code]) {
    $page->setFont(Font::helvetica()->bold(), 9);
    $page->text($col2dX[$i], $y, $label);
    $page->barcode($code, x: $col2dX[$i], y: $y + 2, w: $code2dSize);
}

$y += $code2dSize + 12.0;
$page->setFont(Font::helvetica()->bold(), 9);
$page->text($labelX, $y, 'PDF417');
$page->barcode(\DragonOfMercy\PhpPdf\Barcode\Pdf417::of('phppdf PDF417'), x: $labelX, y: $y + 4, w: 80.0);

$doc->save($fixturesDir . '/barcode/gallery.pdf');
echo "Regenerated barcode/gallery.pdf\n";

// Markdown golden fixtures (cell surface, flowing surface, multi-page flow).
// The build code is shared verbatim with the matching golden test classes via
// their public static buildPdfBytes() helpers, so input never drifts.
$markdownGoldens = [
    'markdown/cell.pdf'      => \DragonOfMercy\PhpPdf\Tests\Golden\MarkdownCellTest::class,
    'markdown/flow.pdf'      => \DragonOfMercy\PhpPdf\Tests\Golden\MarkdownFlowTest::class,
    'markdown/multipage.pdf' => \DragonOfMercy\PhpPdf\Tests\Golden\MarkdownMultiPageTest::class,
];

foreach ($markdownGoldens as $name => $class) {
    file_put_contents($fixturesDir . '/' . $name, $class::buildPdfBytes());
    echo "Regenerated {$name}\n";
}

// PDF/A-2 fixtures (Task 8)
if (!is_dir($fixturesDir . '/pdfa')) {
    mkdir($fixturesDir . '/pdfa', 0755, true);
}
\DragonOfMercy\PhpPdf\Tests\Golden\PdfA2bTest::buildDocument()->save($fixturesDir . '/pdfa/a2b.pdf');
echo "Regenerated pdfa/a2b.pdf\n";
\DragonOfMercy\PhpPdf\Tests\Golden\PdfA2uTest::buildDocument()->save($fixturesDir . '/pdfa/a2u.pdf');
echo "Regenerated pdfa/a2u.pdf\n";
\DragonOfMercy\PhpPdf\Tests\Golden\PdfA3bTest::buildDocument()->save($fixturesDir . '/pdfa/a3b.pdf');
echo "Regenerated pdfa/a3b.pdf\n";

// Table golden fixtures (Tasks 10-13)
if (!is_dir($fixturesDir . '/table')) {
    mkdir($fixturesDir . '/table', 0755, true);
}
file_put_contents($fixturesDir . '/table/table-basic.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TableGoldenTest::buildBasic());
echo "Regenerated table/table-basic.pdf\n";
file_put_contents($fixturesDir . '/table/table-styled.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TableGoldenTest::buildStyled());
echo "Regenerated table/table-styled.pdf\n";
file_put_contents($fixturesDir . '/table/table-paginated.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TableGoldenTest::buildPaginated(true));
file_put_contents($fixturesDir . '/table/table-paginated-noheader.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TableGoldenTest::buildPaginated(false));
echo "Regenerated table/table-paginated*.pdf\n";
file_put_contents($fixturesDir . '/table/table-avatars.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TableGoldenTest::buildAvatars());
echo "Regenerated table/table-avatars.pdf\n";
file_put_contents($fixturesDir . '/table/table-justify.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TableGoldenTest::buildJustified());
echo "Regenerated table/table-justify.pdf\n";
file_put_contents($fixturesDir . '/table/table-colspan.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TableGoldenTest::buildColspan());
echo "Regenerated table/table-colspan.pdf\n";
file_put_contents($fixturesDir . '/table/table-grouped-header.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TableGoldenTest::buildGroupedHeader());
echo "Regenerated table/table-grouped-header.pdf\n";

// Justified cell fixtures (Task 6)
if (!is_dir($fixturesDir . '/page')) {
    mkdir($fixturesDir . '/page', 0755, true);
}
file_put_contents($fixturesDir . '/page/cell-justify.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\CellJustifyGoldenTest::buildStandard());
echo "Regenerated page/cell-justify.pdf\n";
if (is_file(__DIR__ . '/assets/fonts/FreeSans.ttf')) {
    file_put_contents($fixturesDir . '/page/cell-justify-custom.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\CellJustifyGoldenTest::buildCustom());
    echo "Regenerated page/cell-justify-custom.pdf\n";
} else {
    echo "Skipped page/cell-justify-custom.pdf (FreeSans asset absent)\n";
}

// Multi-column golden fixtures (Task 7)
file_put_contents($fixturesDir . '/page/columns-cells.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\ColumnsGoldenTest::buildCells());
echo "Regenerated page/columns-cells.pdf\n";
file_put_contents($fixturesDir . '/page/columns-markdown-1page.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\ColumnsGoldenTest::buildMarkdown1Page());
echo "Regenerated page/columns-markdown-1page.pdf\n";
file_put_contents($fixturesDir . '/page/columns-markdown-2pages.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\ColumnsGoldenTest::buildMarkdown2Pages());
echo "Regenerated page/columns-markdown-2pages.pdf\n";

// Tagged-PDF golden fixtures (Tagged PDF Phase 1)
if (!is_dir($fixturesDir . '/tagging')) {
    mkdir($fixturesDir . '/tagging', 0755, true);
}
file_put_contents($fixturesDir . '/tagging/cell-paragraph.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TaggingGoldenTest::buildCellParagraph());
echo "Regenerated tagging/cell-paragraph.pdf\n";
file_put_contents($fixturesDir . '/tagging/figure.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TaggingGoldenTest::buildFigure());
echo "Regenerated tagging/figure.pdf\n";
file_put_contents($fixturesDir . '/tagging/image-link.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TaggingGoldenTest::buildImageLink());
echo "Regenerated tagging/image-link.pdf\n";
file_put_contents($fixturesDir . '/tagging/table.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TaggingGoldenTest::buildTable());
echo "Regenerated tagging/table.pdf\n";
file_put_contents($fixturesDir . '/tagging/markdown.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TaggingGoldenTest::buildMarkdown());
echo "Regenerated tagging/markdown.pdf\n";
file_put_contents($fixturesDir . '/tagging/markdown-links.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TaggingGoldenTest::buildMarkdownLinks());
echo "Regenerated tagging/markdown-links.pdf\n";
file_put_contents($fixturesDir . '/tagging/markdown-image-link.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TaggingGoldenTest::buildMarkdownImageLink());
echo "Regenerated tagging/markdown-image-link.pdf\n";
file_put_contents($fixturesDir . '/tagging/ua-document.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TaggingGoldenTest::buildUaDocument()->output());
echo "Regenerated tagging/ua-document.pdf\n";
file_put_contents($fixturesDir . '/tagging/ua-links.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TaggingGoldenTest::buildUaLinksDocument()->output());
echo "Regenerated tagging/ua-links.pdf\n";
file_put_contents($fixturesDir . '/tagging/ua-markdown-links.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TaggingGoldenTest::buildUaMarkdownLinks()->output());
echo "Regenerated tagging/ua-markdown-links.pdf\n";
file_put_contents($fixturesDir . '/tagging/ua-image-links.pdf', \DragonOfMercy\PhpPdf\Tests\Golden\TaggingGoldenTest::buildUaImageLinks()->output());
echo "Regenerated tagging/ua-image-links.pdf\n";

$svgFixturesDir = __DIR__ . '/fixtures-svg';
if (!is_dir($svgFixturesDir)) {
    mkdir($svgFixturesDir, 0755, true);
}

$svgBarcodeGoldens = [
    'barcode-svg-qr.svg'         => [\DragonOfMercy\PhpPdf\Tests\Golden\Svg\BarcodeSvgQrTest::class,         'buildSvgBytes'],
    'barcode-svg-datamatrix.svg' => [\DragonOfMercy\PhpPdf\Tests\Golden\Svg\BarcodeSvgDataMatrixTest::class, 'buildSvgBytes'],
    'barcode-svg-aztec.svg'      => [\DragonOfMercy\PhpPdf\Tests\Golden\Svg\BarcodeSvgAztecTest::class,      'buildSvgBytes'],
];

foreach ($svgBarcodeGoldens as $name => [$class, $method]) {
    $bytes = $class::$method();
    file_put_contents($svgFixturesDir . '/' . $name, $bytes);
    echo "Regenerated {$name}\n";
}
