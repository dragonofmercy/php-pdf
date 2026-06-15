<p align="center">
  <img src="https://raw.githubusercontent.com/dragonofmercy/php-pdf/main/.github/og-image.png" alt="phppdf - modern PHP 8.4 library for PDF generation" width="100%">
</p>

# phppdf

[![CI](https://badgen.net/github/checks/dragonofmercy/php-pdf/main?label=CI)](https://github.com/dragonofmercy/php-pdf/actions/workflows/ci.yml)
[![Latest Version](https://badgen.net/packagist/v/dragonofmercy/phppdf)](https://packagist.org/packages/dragonofmercy/phppdf)
[![Total Downloads](https://badgen.net/packagist/dt/dragonofmercy/phppdf)](https://packagist.org/packages/dragonofmercy/phppdf)
[![PHP Version](https://badgen.net/packagist/php/dragonofmercy/phppdf)](https://www.php.net/)
[![License](https://badgen.net/packagist/license/dragonofmercy/phppdf)](LICENSE)

Modern PHP 8.4 library for PDF generation. Pure PHP, no external runtime dependencies beyond the standard `mbstring`, `openssl`, and `zlib` extensions.

> **Status:** stable (1.0). The public API follows [semantic versioning](https://semver.org/); breaking changes are reserved for the next major version. See [CHANGELOG.md](CHANGELOG.md).

## What works today

A quick tour - each feature has a full guide in the [wiki](#documentation).

**Create**

- 📄 **Documents** - 19 standard sizes (ISO A0-A7, B4 / B5, C-series envelopes, Letter / Legal / Tabloid / Executive / Half-Letter) plus custom sizes, portrait or landscape, multi-page, metadata, AES-256 password protection, viewer hints.
- 📐 **Coordinates** - millimetres by default (origin top-left), or PDF points via `Unit::PT`.
- ✏️ **Graphics** - lines, rectangles, circles, paths, fill / stroke, dashes, caps / joins, transforms.
- 🔤 **Text** - the 12 standard fonts, multi-line, configurable leading, full Western Latin, exact width measurement.
- 🔡 **Custom fonts** - register TrueType / OpenType families with full Unicode (Latin / Greek / Cyrillic / CJK), selectable text, automatic glyph subsetting.
- 📦 **Cells** - text boxes with borders, fill, padding, alignment (left / center / right / justify), word-wrap, three width-fit modes.
- 🖼️ **Images** - JPEG and PNG (all color types, transparency), embedded once and placed anywhere.
- 🎨 **SVG** - fully vector: shapes, paths, gradients, patterns, clipping, masks, CSS styling, selectable `<text>` / `<textPath>`, pure-PHP `<filter>`.
- 🔢 **Barcodes & QR** - 1D (EAN-13 / 8, Code 128 / 39 / 93, UPC-A, ITF) and 2D (QR, Aztec, DataMatrix, PDF417), pure-PHP and vector.
- 📝 **Markdown** - a CommonMark core subset, flowing with automatic page breaks or inside an auto-sized cell, styleable via `MarkdownStyle`.
- 📊 **Tables** - data grids via `Page::table()`: fixed or `fill` columns, repeated headers, zebra striping, borders, per-cell styling, column spanning, grouped headers.
- 🧱 **Multi-column layout** - flow `cell()` and `markdown()` across equal-width columns with `$page->columns(...)`.
- 🔖 **Bookmarks & hyperlinks** - a nested table-of-contents sidebar and clickable URL / page areas.
- 🧾 **Interactive forms** - text fields, checkboxes, radios, dropdowns, listboxes, buttons, with per-field styling, JavaScript actions, and field linking.
- ✍️ **Digital signatures** - real PKCS#7 / CMS via `Document::sign()`, RFC 3161 timestamps, multiple signers, PAdES B-B / B-T, and LTV building blocks.

**Right-to-left & accessibility**

- 🔁 **Right-to-left text** - Unicode bidi reordering (UAX #9) plus Arabic cursive shaping (contextual forms + lam-alef ligatures) on cells, tables, and Markdown; per-document, per-cell, or per-block direction.
- 🗄️ **PDF/A archival** - PDF/A-1 (PDF 1.4-based, levels 1b / 1a, transparency rejected), PDF/A-2, PDF/A-3 (levels b / u / a), and PDF 2.0-based PDF/A-4 (+ A-4f) with one `enablePdfA()` call, veraPDF-validated; A-3 / A-4f embed Factur-X / ZUGFeRD e-invoices.
- ♿ **Tagged PDF & PDF/UA-1** - opt-in tagging via `enableTagging()`; `enablePdfUA()` produces veraPDF-validated PDF/UA-1 with artifacts, figure alt text, table header scopes, and tagged hyperlinks.

**Read & modify existing PDFs**

- 📖 **Reading** - `PdfReader` parses PDFs including encrypted ones (RC4 40/128-bit, AES-128, AES-256); pass an optional password or omit it for permissions-only encryption. Classic xref tables, cross-reference streams, incremental revisions, object streams, and common filters.
- 🧩 **Template import (FPDI-style)** - `Document::importPdf()` + `Page::template()` stamp any source page as a background or overlay: letterheads, watermarks, scanned-form fills. Encrypted source PDFs are supported (optional password).
- 🔧 **Modifying** - `PdfEditor::open()` saves changes as appended revisions, leaving the original bytes (and any signatures) intact: edit metadata, add pages, delete and reorder pages (bookmarks and links pointing at a removed page are cleaned up automatically), sign, timestamp, and enable LTV. Editing encrypted PDFs is supported - pass an optional password; signing an encrypted PDF is not yet supported.
- 🖋️ **Filling AcroForm fields** - inspect and `setField()` text / checkbox / radio / combobox / listbox values; each filled field gets a generated appearance. Fields that use a font embedded in the document (not only the standard 14) are supported too. `flattenFields()` freezes filled forms into static page content (all fields or a named subset; signature and button fields are kept). Works on encrypted PDFs.

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
