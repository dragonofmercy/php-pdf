# phppdf

Modern PHP 8.4 library for PDF generation. Pure PHP, no external runtime dependencies beyond the standard `mbstring`, `openssl`, and `zlib` extensions.

> **Status:** work in progress, pre-1.0. The public API is reasonably stable for what is shipped (Phase 2c) but is not yet frozen.

## What works today

- **Document scaffolding** — PDF 1.7 output, deterministic byte-identical fixtures, encryption (RC4 + AES-128), metadata + XMP.
- **Graphics** — lines, rectangles, circles, paths (move/line/curve), fill/stroke, dash patterns, line caps/joins, save/restore, transforms (translate/rotate/scale).
- **Text** — 12 standard PDF fonts (Helvetica / Times / Courier × Regular / Bold / Italic / BoldItalic). WinAnsi encoding (covers western Latin scripts incl. accents and the typographic chars in 0x80-0x9F: `€ — œ Œ ‰` etc.). Multi-line via `\n`, custom leading.
- **Cells** — rectangles with text, borders (per-side, with width / color / style: solid / dashed / dotted), fill, padding, alignment (left / center / right × top / middle / bottom), three fit modes (none / condense / shrink), word-wrap with automatic force-break.
- **Text measurement** — `$page->stringWidth(...)` using AFM metrics for the 12 standard fonts.

## Not yet implemented

- Custom fonts (TTF / OTF) and full Unicode (CJK, Cyrillic, Greek, Hebrew, etc.) — Phase 3, deferred.
- Images (JPEG / PNG / SVG) — Phase 4.
- Outlines / hyperlinks, form fields, digital signatures, HTML/CSS rendering — later phases.

## Installation

```bash
composer require dragonofmercy/phppdf
```

## Usage

### Empty document

```php
use PhpPdf\Document;

$pdf = new Document();
$pdf->addPage();
$pdf->save('out.pdf');
```

`$pdf->output()` returns the PDF bytes as a string instead of writing to disk.

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

### Graphics

```php
use PhpPdf\Color;

$page = $pdf->addPage();
$page->setStrokeColor(Color::hex('#ff0000'))
     ->setLineWidth(1)
     ->rect(20, 20, 100, 50)
     ->stroke();

$page->setFillColor(Color::rgb(0, 0, 255))
     ->circle(200, 200, 40)
     ->fill();
```

### Text

```php
use PhpPdf\Font;

$page->setFont(Font::helvetica()->bold(), 18);
$page->text(50, 50, 'Hello World');

$page->setFont(Font::times()->italic(), 12);
$page->text(50, 100, 'Resume - cafe, naivete, oeuvre');

$page->setFont(Font::courier(), 10);
$page->text(50, 150, "Line 1\nLine 2\nLine 3");
```

### Cells

```php
use PhpPdf\Border;
use PhpPdf\BorderStyle;
use PhpPdf\Fit;
use PhpPdf\TextAlign;
use PhpPdf\VerticalAlign;

$page->setFont(Font::helvetica(), 12);

// Header centered, bordered, filled.
$page->cell(
    x: 50, y: 50, w: 300, h: 25,
    text: 'Invoice #2026-001',
    border: Border::all()->withWidth(0.8),
    fill: Color::rgb(242, 242, 242),
    align: TextAlign::CENTER,
    verticalAlign: VerticalAlign::MIDDLE,
);

// Wrapping prose with dashed border.
$result = $page->cell(
    x: 50, y: 80, w: 300,
    text: 'Long paragraph that wraps automatically across multiple lines.',
    border: Border::all()->withStyle(BorderStyle::DASHED),
);

// Right-aligned with custom text color.
$page->cell(
    x: 50, y: $result->y + 5, w: 300, h: 20,
    text: 'Total: 1234.56 EUR',
    textColor: Color::rgb(192, 0, 0),
    align: TextAlign::RIGHT,
);

// Long word condensed to fit a narrow cell.
$page->cell(
    x: 50, y: 200, w: 100, h: 20,
    text: 'Antidisestablishmentarianism',
    border: Border::all(),
    fit: Fit::CONDENSE,
);
```

`cell()` returns a `CellResult` carrying `x`, `y` (the bottom-right anchor for stacking), `height`, `lineCount`, `brokenWords`, and `textOverflow`.

### Text measurement

```php
$page->setFont(Font::helvetica(), 12);
$width = $page->stringWidth('Hello'); // 27.336 (points)
```

## Development

The library lives entirely under `build/`. Clone the repo, then:

```bash
cd build/
composer install
composer check   # PHPStan max + PHPUnit (unit + golden)
```

`composer test` runs the full suite (328 tests at Phase 2c). `composer analyse` runs PHPStan at level max.

### Golden tests

Six binary fixtures under `tests/Golden/fixtures/` are byte-compared against fresh renders. Each fixture has an associated `qpdf --check` validation that skips cleanly if qpdf is absent. To install qpdf:

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
