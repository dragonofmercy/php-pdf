# phppdf

Modern PHP 8.4 library for PDF generation. Pure PHP, no external runtime dependencies beyond the standard `mbstring`, `openssl`, and `zlib` extensions.

> **Status:** work in progress, pre-1.0. The public API is reasonably stable for what is shipped (Phase 2c) but is not yet frozen.

## What works today

- **Document scaffolding** — PDF 1.7 output, deterministic byte-identical fixtures, encryption (RC4 + AES-128), metadata + XMP, viewer preferences (page layout, page mode, initial open action).
- **Pages** — standard formats (A3, A4, A5, A6, Letter, Legal) with portrait / landscape orientation, plus arbitrary custom dimensions for labels and similar. Coordinates and sizes default to millimetres; switch to PDF points with `Unit::PT`.
- **Graphics** — lines, rectangles, circles, paths (move/line/curve), fill/stroke, dash patterns, line caps/joins, save/restore, transforms (translate/rotate/scale).
- **Text** — 12 standard PDF fonts (Helvetica / Times / Courier × Regular / Bold / Italic / BoldItalic). WinAnsi encoding (covers western Latin scripts incl. accents and the typographic chars in 0x80-0x9F: `EUR -- oe Oe %.` etc.). Multi-line via `\n`, custom leading.
- **Cells** — rectangles with text, borders (per-side, with width / color / style: solid / dashed / dotted), fill, padding, alignment (left / center / right * top / middle / bottom), three fit modes (none / condense / shrink), word-wrap with automatic force-break.
- **Text measurement** — `$page->stringWidth(...)` using AFM metrics for the 12 standard fonts.
- **Images** — JPEG (RGB / Gray / CMYK) and PNG (RGB / Gray / Palette / RGB+Alpha / Gray+Alpha / Palette+tRNS) embedded as XObjects. Soft-mask transparency for PNG alpha channels. Auto-format detection by magic bytes. Per-document caching: same path / instance reuses one XObject across multiple placements.

## Not yet implemented

- Custom fonts (TTF / OTF) and full Unicode (CJK, Cyrillic, Greek, Hebrew, etc.) -- Phase 3, deferred.
- SVG vector images -- later phase.
- Outlines / hyperlinks, form fields, digital signatures, HTML/CSS rendering -- later phases.

## Installation

```bash
composer require dragonofmercy/phppdf
```

## Usage

### Empty document

```php
use DragonOfMercy\PhpPdf\Document;

$pdf = new Document();
$pdf->addPage();
$pdf->save('out.pdf');
```

`$pdf->output()` returns the PDF bytes as a string instead of writing to disk.

### Pages and units

By default the document works in millimetres. Font sizes and leading stay in PDF points (typographic convention). Switch the whole document to points with `Unit::PT`.

```php
use DragonOfMercy\PhpPdf\{Document, PageFormat, Orientation, Unit};

$pdf = new Document();                              // mm by default
$pdf->addPage();                                    // A4 portrait
$pdf->addPage(PageFormat::A6);                      // A6 portrait
$pdf->addPage();                                    // A6 portrait (remembered)
$pdf->addPage(orientation: Orientation::LANDSCAPE); // last format in landscape
$pdf->addPage([99, 38]);                            // custom 99x38 mm (label)

// Available formats: A3, A4, A5, A6, LETTER, LEGAL.

// To work in PDF points instead:
$pdf = new Document(Unit::PT);
$pdf->addPage(); // 595.28 x 841.89 pt
```

The document remembers the last format and orientation, so a multi-page label sheet only needs `addPage([99, 38])` once. Passing a `PageFormat` clears any custom dimensions; for custom arrays, the orientation argument is ignored (you provide the dimensions in the order you want).

### Metadata + encryption

```php
$pdf = new Document();
$pdf->metadata()
    ->title('Invoice 2026-001')
    ->author('Acme Corp')
    ->creationDate(new DateTimeImmutable());
$pdf->encryption()
    ->userPassword('user')
    ->ownerPassword('owner')
    ->allowPrint();
$pdf->addPage();
$pdf->save('invoice.pdf');
```

### Viewer preferences

Hints stored in the catalog that the PDF viewer applies when opening the document. Three independent setters, all optional. Equivalent to TCPDF's `setDisplayMode()` but split into typed setters with named-constructor value objects.

```php
use DragonOfMercy\PhpPdf\{OpenAction, PageLayout, PageMode};

$pdf->setPageLayout(PageLayout::TWO_COLUMN_RIGHT) // single page / one column / two-column / two-page; *Right starts on the right (book/magazine)
    ->setPageMode(PageMode::USE_OUTLINES)         // none / outlines / thumbs / full screen / OC layers / attachments
    ->setOpenAction(OpenAction::fitWidth(top: 0)); // initial view: page + zoom/fit
```

`OpenAction` constructors (page is 1-indexed, defaults to 1):

```php
OpenAction::fit($page);                                   // entire page fits in viewport
OpenAction::fitWidth($page, top: 50);                     // page width fills viewport, top at 50 mm from page top
OpenAction::fitHeight($page, left: 0);                    // page height fills viewport
OpenAction::zoom($page, left: 10, top: 20, zoom: 1.5);    // top-left corner at (10, 20) mm, zoomed 150%
OpenAction::actualSize($page);                            // 100% zoom anchored at top-left
```

Coordinates use the document's unit and a top-down Y axis (consistent with the rest of the API). They are converted to PDF native (bottom-up, points) at serialisation. Out-of-range page indices throw `PdfException` at output time.

Pass `null` to any setter to clear it. These are hints: Acrobat respects them faithfully, browser viewers (Chrome, Firefox PDF.js) honour some and ignore others, notably full-screen mode.

### Graphics

All coordinates and sizes are in the document's unit (millimetres by default).

```php
use DragonOfMercy\PhpPdf\Color;

$page = $pdf->addPage();
$page->setStrokeColor(Color::hex('#ff0000'))
     ->setLineWidth(0.5)               // 0.5 mm
     ->rect(20, 20, 80, 40)            // 80x40 mm at (20, 20) mm
     ->stroke();

$page->setFillColor(Color::rgb(0, 0, 255))
     ->circle(105, 150, 20)             // centre at (105, 150) mm, r = 20 mm
     ->fill();
```

### Text

```php
use DragonOfMercy\PhpPdf\Font;

// Font size is in points, regardless of the document unit.
$page->setFont(Font::helvetica()->bold(), 18);
$page->text(20, 30, 'Hello World');     // (20, 30) mm

$page->setFont(Font::times()->italic(), 12);
$page->text(20, 50, 'Resume - cafe, naivete, oeuvre');

$page->setFont(Font::courier(), 10);
$page->text(20, 70, "Line 1\nLine 2\nLine 3");
```

### Cells

```php
use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\BorderStyle;
use DragonOfMercy\PhpPdf\Fit;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\VerticalAlign;

$page->setFont(Font::helvetica(), 12);

// Header centered, bordered, filled.
$page->cell(
    x: 20, y: 20, w: 170, h: 10,
    text: 'Invoice #2026-001',
    border: Border::all()->withWidth(0.3),
    fill: Color::rgb(242, 242, 242),
    align: TextAlign::CENTER,
    verticalAlign: VerticalAlign::MIDDLE,
);

// Wrapping prose with dashed border.
$result = $page->cell(
    x: 20, y: 35, w: 170,
    text: 'Long paragraph that wraps automatically across multiple lines.',
    border: Border::all()->withStyle(BorderStyle::DASHED),
);

// Right-aligned with custom text color.
$page->cell(
    x: 20, y: $result->y + 2, w: 170, h: 8,
    text: 'Total: 1234.56 EUR',
    textColor: Color::rgb(192, 0, 0),
    align: TextAlign::RIGHT,
);

// Long word condensed to fit a narrow cell.
$page->cell(
    x: 20, y: 80, w: 40, h: 8,
    text: 'Antidisestablishmentarianism',
    border: Border::all(),
    fit: Fit::CONDENSE,
);
```

`cell()` returns a `CellResult` carrying `x`, `y` (the bottom-right anchor for stacking, in the document's unit), `height`, `lineCount`, `brokenWords`, and `textOverflow`.

### Text measurement

```php
$page->setFont(Font::helvetica(), 12);
$width = $page->stringWidth('Hello'); // ~9.64 (mm), or ~27.34 in PT mode
```

### Images

```php
use DragonOfMercy\PhpPdf\Image;

// Path string: format auto-detected, file read once, cached for the document.
$page->image('logo.png', x: 20, y: 20, w: 40, h: 20);   // 40x20 mm

// Instance: read bytes elsewhere, embed when convenient.
$photo = Image::fromFile('photo.jpg');
$page->image($photo, x: 20, y: 60, w: 80);              // h derived from aspect ratio

// Same path used twice -> one XObject embedded, two placements.
$page->image('logo.png', x: 150, y: 20, w: 30, h: 15);
```

Dimension rules:
- Both `w` and `h` provided -> forced (may distort).
- Only `w` -> `h` derived to preserve aspect ratio.
- Only `h` -> `w` derived to preserve aspect ratio.
- Neither -> intrinsic pixel size at 72 DPI (1 pixel = 1 point ~= 0.353 mm).

`(x, y)` is the **top-left** corner in the page user space (Y-down origin, consistent with the rest of phppdf since Phase 2a).

## Development

The library lives entirely under `build/`. Clone the repo, then:

```bash
cd build/
composer install
composer check   # PHPStan max + PHPUnit (unit + golden)
```

`composer test` runs the full suite (449 tests at Phase 4). `composer analyse` runs PHPStan at level max.

### Golden tests

Seven binary fixtures under `tests/Golden/fixtures/` are byte-compared against fresh renders. Each fixture has an associated `qpdf --check` validation that skips cleanly if qpdf is absent. To install qpdf:

- Linux: `sudo apt-get install qpdf`
- macOS: `brew install qpdf`
- Windows: `choco install qpdf`

When you intentionally change the generator output:

```bash
php tests/Golden/regenerate.php
```

Then commit the regenerated fixture(s) alongside the code change.

### Generating the standard font metrics

The 12 AFM-derived metrics PHP files in `src/Font/Metrics/` are regenerated from Adobe Type 1 AFM source files placed in `bin/afm-source/` (gitignored):

```bash
php bin/generate-font-metrics.php
```

The script handles the WinAnsi glyph-name mapping and emits one PHP file per font.

## License

MIT - see [LICENSE](LICENSE).

## Support

If this project helps to increase your productivity, you can give me a cup of coffee :)

<a href="https://ko-fi.com/dragonofmercy" target="_blank"><img src="https://cdn.ko-fi.com/cdn/kofi2.png?v=3" alt="Donate" width="160px" /></a>
