<p align="center">
  <img src="https://raw.githubusercontent.com/dragonofmercy/php-pdf/main/.github/og-image.png" alt="phppdf - modern PHP 8.4 library for PDF generation" width="100%">
</p>

# phppdf

[![CI](https://img.shields.io/github/actions/workflow/status/dragonofmercy/php-pdf/ci.yml?branch=main&label=CI)](https://github.com/dragonofmercy/php-pdf/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/dragonofmercy/phppdf.svg)](https://packagist.org/packages/dragonofmercy/phppdf)
[![Total Downloads](https://img.shields.io/packagist/dt/dragonofmercy/phppdf.svg)](https://packagist.org/packages/dragonofmercy/phppdf)
[![PHP Version](https://img.shields.io/packagist/php-v/dragonofmercy/phppdf.svg)](https://www.php.net/)
[![License](https://img.shields.io/packagist/l/dragonofmercy/phppdf.svg)](LICENSE)

Modern PHP 8.4 library for PDF generation. Pure PHP, no external runtime dependencies beyond the standard `mbstring`, `openssl`, and `zlib` extensions.

> **Status:** stable (1.0). The public API follows [semantic versioning](https://semver.org/); breaking changes are reserved for the next major version. See [CHANGELOG.md](CHANGELOG.md).

## What works today

A quick tour - each feature has a full guide in the [wiki](#documentation).

- **Documents** - standard formats (A4 / Letter / Legal and more) and custom sizes, portrait or landscape, multi-page, metadata, AES-256 password protection, and viewer hints.
- **Coordinates** - millimetres by default (origin top-left, Y pointing down), or PDF points via `Unit::PT`.
- **Graphics** - lines, rectangles, circles, paths, fill / stroke, dashes, line caps / joins, and transforms.
- **Text** - the 12 standard PDF fonts, multi-line text, configurable leading, and full Western Latin (accents and typographic characters).
- **Custom TrueType / OpenType fonts** - register your own families and use them like the built-ins: full Unicode reach (Latin / Greek / Cyrillic / CJK), selectable text, and automatic glyph subsetting for small files.
- **Cells** - text boxes with borders, fill, padding, alignment (left / center / right / justify), automatic word-wrap, and three width-fit modes.
- **Text measurement** - `$page->stringWidth(...)` returns the exact width of a string in the current font.
- **Images** - JPEG and PNG (all color types, with transparency), auto-detected, embedded once and placed as many times as you like.
- **SVG vector images** - inline or from a file, fully vector: shapes, paths, gradients, patterns, clipping, masks, CSS styling, embedded rasters, real selectable `<text>` / `<textPath>`, and a pure-PHP `<filter>` raster.
- **Barcodes & QR codes** - 1D (EAN-13 / 8, Code 128 / 39 / 93, UPC-A, ITF) and 2D (QR, Aztec, DataMatrix, PDF417), all pure-PHP and vector, with optional human-readable text and vertical 1D rendering.
- **Bookmarks & hyperlinks** - a nested table-of-contents sidebar and clickable areas that open a URL or jump to another page.
- **Interactive forms** - fillable text fields, checkboxes, radio buttons, dropdowns, listboxes, and push buttons, with per-field styling, JavaScript actions (calculation / formatting / validation), and automatic field linking.
- **Digital signatures** - sign with a real PKCS#7 / CMS signature via `Document::sign()` and a PKCS#12 credential, with RFC 3161 timestamps, multiple signers, the strict PAdES profiles (B-B / B-T), and long-term-validation (LTV) building blocks.
- **Markdown** - render a CommonMark core subset, either flowing with automatic page breaks (`Page::markdown()`) or inside an auto-sized cell; styleable via `MarkdownStyle`.
- **Tables** - data grids via `Page::table()`: fixed or `fill` columns, headers repeated across pages, zebra striping, borders, a per-cell style callback, text-or-image cells, column spanning (`colSpan`), and grouped headers.
- **Right-to-left text** - Unicode bidirectional reordering (UAX #9) for Hebrew, Arabic, and other RTL scripts on cells, tables, and Markdown. Set a document base direction with `setBaseDirection()`, override per cell with `direction:`, per table cell with `Cell::direction()`, or per Markdown block with the `direction:` argument on `Page::markdown()` / `cell(markdown: true)`; `Direction::AUTO` detects the base from the text. An RTL base right-aligns by default. No page-coordinate flip - layout, images, and tables are unaffected. Arabic cursive shaping is included: letters are joined using the correct contextual presentation forms (isolated / initial / medial / final) and the four mandatory lam-alef ligatures are formed automatically; the font's cmap must contain the Arabic presentation forms (e.g. GNU FreeSerif, DejaVu Sans, Tahoma - modern GSUB-only fonts that omit the legacy presentation-form block are not supported). Markdown RTL: bidi reordering and Arabic shaping apply to each Markdown block, RTL blocks are right-aligned, and list markers / blockquote bars are mirrored to the right side; inline code and fenced code blocks stay LTR.
- **Multi-column layout** - flow `cell()` and `markdown()` across equal-width columns with `$page->columns(...)`, filling each column before the next and continuing onto new pages.
- **PDF/A archival** - emit PDF/A-2 and PDF/A-3 (levels b, u, and a) with one `enablePdfA()` call, validated against veraPDF; A-3 embeds associated files such as a Factur-X / ZUGFeRD e-invoice. Level A (`PdfALevel::A2A` / `A3A`) auto-enables tagging and requires the catalog language, e.g. `enablePdfA(PdfALevel::A2A, 'en-US')`. Combining `enablePdfA(PdfALevel::A2A, 'en-US')` with `enablePdfUA('en-US')` produces a single file that is both PDF/A-2a and PDF/UA-1.
- **Tagged PDF & PDF/UA-1 accessibility** - opt-in structure tagging via `enableTagging()`: cells, images, tables, and Markdown are tagged automatically into a logical structure tree (`StructTreeRoot`, `MarkInfo`, ParentTree, marked content), with an optional document language. `enablePdfUA()` goes further and produces output that validates as PDF/UA-1 (`isCompliant` under veraPDF): decoration is marked as `/Artifact`, figures carry alternate text (`image(alt: ...)`), table headers get a scope, `DisplayDocTitle` and an XMP `pdfuaid` are emitted, and a fail-fast guard enforces embedded fonts, a title, and figure alt text. Text hyperlinks made with `cell(link: ...)` are tagged as accessible `<Link>` elements (with `/OBJR`, `/StructParent`, and a description). Markdown inline links are also tagged automatically as `<Link>` elements when tagging is on, so `markdown()` with links is PDF/UA-1 conformant. Image hyperlinks are tagged on both surfaces: `Page::image(link: ..., linkAlt: ...)` and Markdown block image links `[![alt](img)](url)` both emit a `<Link>` wrapping the `<Figure>`, keeping documents with image links PDF/UA-1 conformant.

- **Reading existing PDFs (low-level)** - `PdfReader` parses any non-encrypted PDF (classic xref tables and cross-reference streams, incremental revisions, object streams, the common stream filters) and exposes its objects and page tree. This is the foundation for template import and modification.
- **Template import (FPDI-style)** - `Document::importPdf()` / `importPdfBytes()` parse an existing PDF and `Page::template($tpl, $x, $y, $width, $height)` draws any of its pages as an opaque background or stamp: letterheads, overlays on scanned forms, watermarking. Page rotation is honored, the source page's resources are carried over, and templates work together with encryption, tagging (drawn as artifacts), and signatures.
- **Modifying existing PDFs** - `Pdf::open($path)` (or `Pdf::fromBytes()`) opens a non-encrypted PDF and writes changes as an appended incremental revision, leaving the original bytes - and any signatures they carry - byte-for-byte intact. Update document metadata (`setTitle` / `setAuthor` / `setSubject` / `setKeywords` / `setCreator`, with the XMP packet refreshed when present) and append new pages with the full page API via `appendPage()`. Works on both classic xref tables and cross-reference streams.
- **Filling AcroForm fields** - read an existing form with `Pdf::open()`, inspect its fields via `formFields()` / `field()`, set values with `setField($name, $value)` for text (single-line and multiline), checkbox (bool), radio (export-name string), combobox (export key), and listbox (string or array of export keys), and write the result as an incremental revision. Each filled field receives a generated appearance stream (`/AP`) so the value is visible without viewer-side rendering. Two limitations apply: appearance generation supports Standard-14 `/DR` fonts only (fields whose default-resource font is embedded/non-standard throw), and fields are not flattened (they stay interactive).

## Not yet implemented

- Signing existing PDFs via incremental revision.
- Bidi explicit embedding/override/isolate controls, and per-column-header direction in tables.
- Bidi explicit embedding/override/isolate controls, and per-column-header direction in tables.

## Installation

```bash
composer require dragonofmercy/phppdf
```

## Quick start

```php
use DragonOfMercy\PhpPdf\Document;

$pdf = new Document();
$pdf->addPage();
$pdf->save('out.pdf');
```

`$pdf->output()` returns the PDF bytes as a string instead of writing to disk.

## Documentation

Full usage documentation lives in the [wiki](https://github.com/dragonofmercy/php-pdf/wiki):

- [Getting Started](https://github.com/dragonofmercy/php-pdf/wiki/Getting-Started), [Examples](https://github.com/dragonofmercy/php-pdf/wiki/Examples)
- [Text and Fonts](https://github.com/dragonofmercy/php-pdf/wiki/Text-and-Fonts), [Cells](https://github.com/dragonofmercy/php-pdf/wiki/Cells), [Tables](https://github.com/dragonofmercy/php-pdf/wiki/Tables), [Graphics](https://github.com/dragonofmercy/php-pdf/wiki/Graphics), [Images](https://github.com/dragonofmercy/php-pdf/wiki/Images)
- [Barcodes and QR Codes](https://github.com/dragonofmercy/php-pdf/wiki/Barcodes-and-QR-Codes), [SVG Support](https://github.com/dragonofmercy/php-pdf/wiki/SVG-Support)
- [Markdown](https://github.com/dragonofmercy/php-pdf/wiki/Markdown), [Bookmarks and Hyperlinks](https://github.com/dragonofmercy/php-pdf/wiki/Bookmarks-and-Hyperlinks), [Viewer Preferences](https://github.com/dragonofmercy/php-pdf/wiki/Viewer-Preferences), [Metadata and Encryption](https://github.com/dragonofmercy/php-pdf/wiki/Metadata-and-Encryption)
- [Interactive Forms](https://github.com/dragonofmercy/php-pdf/wiki/Interactive-Forms), [Digital Signatures](https://github.com/dragonofmercy/php-pdf/wiki/Digital-Signatures), [PDF/A Conformance](https://github.com/dragonofmercy/php-pdf/wiki/PDF-A-Conformance)
- Internals and contributing: [Architecture](https://github.com/dragonofmercy/php-pdf/wiki/Internals-Architecture), [Contributing](https://github.com/dragonofmercy/php-pdf/wiki/Contributing)

## Development

The library source lives under `build/`. To get started:

```bash
git clone https://github.com/dragonofmercy/php-pdf.git
cd php-pdf/build
composer install
composer check   # PHPStan (level max) + PHPUnit
```

See the [Contributing](https://github.com/dragonofmercy/php-pdf/wiki/Contributing) wiki page for coding conventions, golden fixture workflow, and how to add new features.

## License

MIT - see [LICENSE](LICENSE).

## Support

If this project helps to increase your productivity, you can give me a cup of coffee :)

<a href="https://ko-fi.com/dragonofmercy" target="_blank"><img src="https://cdn.ko-fi.com/cdn/kofi2.png?v=3" alt="Donate" width="160px" /></a>
