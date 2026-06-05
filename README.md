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
- **Multi-column layout** - flow `cell()` and `markdown()` across equal-width columns with `$page->columns(...)`, filling each column before the next and continuing onto new pages.
- **PDF/A archival** - emit PDF/A-2 and PDF/A-3 (levels b and u) with one `enablePdfA()` call, validated against veraPDF; A-3 embeds associated files such as a Factur-X / ZUGFeRD e-invoice.
- **Tagged PDF (accessibility)** - opt-in structure tagging via `enableTagging()`: cells, images, tables, and Markdown are tagged automatically into a logical structure tree (`StructTreeRoot`, `MarkInfo`, ParentTree, marked content), with an optional document language. This is the foundation for PDF/UA; alternate text and full conformance come in a later phase.

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
