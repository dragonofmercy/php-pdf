<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;

$fixturesDir = __DIR__ . '/fixtures';
if (!is_dir($fixturesDir)) {
    mkdir($fixturesDir, 0755, true);
}

$zeros = fn (int $n): string => str_repeat("\x00", $n);

// Fixture 1: empty page without metadata (Phase 0 compat)
$doc = new Document();
$doc->addPage();
$doc->save($fixturesDir . '/empty-page.pdf');
echo "Regenerated empty-page.pdf\n";

// Fixture 2: document with metadata (Phase 1a)
$doc = new Document();
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
$doc = new Document();
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
$doc = new Document();
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
$doc = new Document();
$page = $doc->addPage();

$page->setFont(Font::helvetica()->bold(), 18);
$page->text(50, 50, 'Hello World');

$page->setFont(Font::times()->italic(), 12);
$page->text(50, 100, 'Résumé — café, naïveté, œuvre');

$page->setFont(Font::courier(), 10);
$page->text(50, 150, "Line 1\nLine 2\nLine 3");

$page->setFont(Font::helvetica(), 14);
$page->text(50, 220, 'Prix : 19,99 €');

$doc->save($fixturesDir . '/page-with-text.pdf');
echo "Regenerated page-with-text.pdf\n";

// Fixture 6: page with cells (Phase 2c)
$doc = new Document();
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
$doc = new Document();
$page = $doc->addPage();

// JPEG RGB - 32x16 cyan-magenta gradient.
$jpegSrc = imagecreatetruecolor(32, 16);
if ($jpegSrc === false) {
    throw new RuntimeException('imagecreatetruecolor failed');
}
for ($x = 0; $x < 32; $x++) {
    $r = min(255, max(0, (int) round($x * 255 / 31)));
    $b = min(255, max(0, 255 - $r));
    $color = imagecolorallocate($jpegSrc, $r, 0, $b);
    if ($color === false) {
        throw new RuntimeException('imagecolorallocate failed');
    }
    imageline($jpegSrc, $x, 0, $x, 15, $color);
}
ob_start();
imagejpeg($jpegSrc, null, 90);
$jpegBytes = ob_get_clean();
imagedestroy($jpegSrc);
if ($jpegBytes === false || $jpegBytes === '') {
    throw new RuntimeException('JPEG capture failed');
}
$jpegImage = \DragonOfMercy\PhpPdf\Image::fromBytes($jpegBytes);

// PNG RGB opaque - 24x12 horizontal red-green-blue strips.
$pngSrc = imagecreatetruecolor(24, 12);
if ($pngSrc === false) {
    throw new RuntimeException('imagecreatetruecolor failed');
}
$red = imagecolorallocate($pngSrc, 255, 0, 0);
$green = imagecolorallocate($pngSrc, 0, 255, 0);
$blue = imagecolorallocate($pngSrc, 0, 0, 255);
if ($red === false || $green === false || $blue === false) {
    throw new RuntimeException('imagecolorallocate failed');
}
imagefilledrectangle($pngSrc, 0, 0, 23, 3, $red);
imagefilledrectangle($pngSrc, 0, 4, 23, 7, $green);
imagefilledrectangle($pngSrc, 0, 8, 23, 11, $blue);
ob_start();
imagepng($pngSrc);
$pngOpaque = ob_get_clean();
imagedestroy($pngSrc);
if ($pngOpaque === false || $pngOpaque === '') {
    throw new RuntimeException('PNG capture failed');
}
$pngOpaqueImage = \DragonOfMercy\PhpPdf\Image::fromBytes($pngOpaque);

// PNG with alpha - 16x16 with a translucent diagonal.
$pngAlphaSrc = imagecreatetruecolor(16, 16);
if ($pngAlphaSrc === false) {
    throw new RuntimeException('imagecreatetruecolor failed');
}
imagealphablending($pngAlphaSrc, false);
imagesavealpha($pngAlphaSrc, true);
$transparent = imagecolorallocatealpha($pngAlphaSrc, 0, 0, 0, 127);
$semi = imagecolorallocatealpha($pngAlphaSrc, 200, 50, 50, 64);
if ($transparent === false || $semi === false) {
    throw new RuntimeException('imagecolorallocatealpha failed');
}
imagefilledrectangle($pngAlphaSrc, 0, 0, 15, 15, $transparent);
for ($i = 0; $i < 16; $i++) {
    imagesetpixel($pngAlphaSrc, $i, $i, $semi);
    imagesetpixel($pngAlphaSrc, 15 - $i, $i, $semi);
}
ob_start();
imagepng($pngAlphaSrc);
$pngAlpha = ob_get_clean();
imagedestroy($pngAlphaSrc);
if ($pngAlpha === false || $pngAlpha === '') {
    throw new RuntimeException('PNG alpha capture failed');
}
$pngAlphaImage = \DragonOfMercy\PhpPdf\Image::fromBytes($pngAlpha);

// PNG palette - built via TestImageFactory to ensure color type 3.
require_once __DIR__ . '/../Support/TestImageFactory.php';
$paletteBytes = \DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory::pngPalette(width: 8, height: 8);
$paletteImage = \DragonOfMercy\PhpPdf\Image::fromBytes($paletteBytes);

// Placements:
$page->image($jpegImage, x: 50, y: 50, w: 200, h: 100);            // forced both
$page->image($pngOpaqueImage, x: 50, y: 200, w: 150);              // w-only (h derived)
$page->image($pngAlphaImage, x: 250, y: 200, w: 100, h: 100);      // forced both
$page->image($paletteImage, x: 400, y: 200);                        // intrinsic 8x8

$doc->save($fixturesDir . '/page-with-images.pdf');
echo "Regenerated page-with-images.pdf\n";
