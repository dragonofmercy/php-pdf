# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-05-29

First stable release. The public API is now frozen under semantic versioning:
breaking changes are reserved for a future major version.

### Documents and layout
- A4 / Letter / Legal and many standard page formats, custom dimensions, portrait or landscape, multi-page.
- Document metadata (title, author, subject, keywords, creation / modification dates).
- Password protection: RC4 40-bit and 128-bit, plus AES-128.
- Viewer preferences: initial zoom, page layout, page mode, and an open action.
- Coordinates in millimetres by default (top-left origin, Y axis down) or PDF points via `Unit::PT`.

### Graphics and text
- Lines, rectangles, circles, arbitrary paths, fill / stroke, dashed lines, line caps and joins, transforms, and graphics-state save / restore.
- The 12 standard PDF fonts with multi-line text, configurable leading, and Western Latin coverage.
- Custom TrueType / OpenType fonts (regular / bold / italic / boldItalic) with full Unicode reach and automatic glyph subsetting (TTF and CFF, name-keyed and CID-keyed).
- Cells: per-side borders (solid / dashed / dotted), fill, padding, horizontal and vertical alignment, three width-fit modes (none / condense / shrink), word-wrap, and force-break. Cursor flow via the `NextPosition` enum.
- Exact string measurement via `Page::stringWidth()`.

### Images and vector graphics
- JPEG and PNG (RGB / Gray / Palette / RGB+Alpha / Gray+Alpha) with transparency and per-document embed caching.
- Inline or file SVG: shapes, all path commands (including arcs), transforms, groups, `<use>`, `viewBox` + `preserveAspectRatio`, fills and strokes with opacity, dash patterns, 147 named CSS colors, linear and radial gradients (including per-stop alpha and spread methods), `<pattern>` tiling, `<symbol>` / `<marker>`, `<clipPath>`, `<mask>` luminance soft masks, `<style>` CSS, embedded raster `<image>`, and `<text>` / `<tspan>` as real selectable text.

### Barcodes
- 1D: EAN-13, EAN-8, Code 128 (auto A/B/C switching), UPC-A, Code 39, Code 93, ITF (with optional GS1 bearer bar). Optional human-readable text, configurable color, vertical rendering via `->vertical()`, and module-driven auto-width via `withModuleSize()` (`SizedBarcode`).
- 2D: QR Code (ISO 18004, V1-V40, EC L/M/Q/H), Aztec Code (ISO 24778, Compact and Full Range), DataMatrix (ISO 16022 ECC200 squares), and PDF417 (ISO 15438 standard). Automatic UTF-8 ECI handling.
- `Page::barcode()` places a code at an absolute position; the optional `NextPosition $ln` parameter advances the cursor like `cell()`.
- Standalone SVG export for the three 2D matrix formats via `SvgBarcodeRenderer`.

### Navigation and interactivity
- Bookmarks (nested outline tree) and clickable hyperlinks (URL or in-document destination).
- Interactive forms: text fields (single / multi-line / password), checkboxes, grouped radio buttons, comboboxes, listboxes, push buttons (ResetForm, OpenUrl, SubmitForm in FDF / HTML / XFDF / PDF), and signature fields.
- Field styling (border color / width / style, background, text color, font, size, alignment), visibility flags, decoupled default values, page tab order, JavaScript actions (format / calculate / validate), document-level scripts, and automatic same-name field linking.
- Real digital signatures: PKCS#7 / CMS detached signatures via `Document::sign()` with a PKCS#12 credential.

### Quality
- PHPStan at level max with zero suppressions across `src/` and `tests/`.
- Byte-identity golden tests for rendered output, with paired `qpdf --check` structural validation.
- Barcode output cross-validated against zxing-cpp and libdmtx; SVG validated by rendering with pdfium.

[1.0.0]: https://github.com/dragonofmercy/php-pdf/releases/tag/v1.0.0
