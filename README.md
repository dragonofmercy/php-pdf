# phppdf

[![CI](https://img.shields.io/github/actions/workflow/status/dragonofmercy/php-pdf/ci.yml?branch=main&label=CI)](https://github.com/dragonofmercy/php-pdf/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/dragonofmercy/phppdf.svg)](https://packagist.org/packages/dragonofmercy/phppdf)
[![Total Downloads](https://img.shields.io/packagist/dt/dragonofmercy/phppdf.svg)](https://packagist.org/packages/dragonofmercy/phppdf)
[![PHP Version](https://img.shields.io/packagist/php-v/dragonofmercy/phppdf.svg)](https://www.php.net/)
[![License](https://img.shields.io/packagist/l/dragonofmercy/phppdf.svg)](LICENSE)

Modern PHP 8.4 library for PDF generation. Pure PHP, no external runtime dependencies beyond the standard `mbstring`, `openssl`, and `zlib` extensions.

> **Status:** stable (1.0). The public API follows [semantic versioning](https://semver.org/); breaking changes are reserved for the next major version. See [CHANGELOG.md](CHANGELOG.md).

## What works today

- **Documents** - A4 / Letter / Legal and many other standard formats, custom dimensions for labels, portrait or landscape, multi-page, metadata (title, author, dates), password protection (AES-256, PDF 2.0), and the usual viewer hints (initial zoom, page layout, bookmarks panel open on launch).
- **Coordinates** - millimetres by default, with the origin at the top-left of the page (Y axis pointing down). Switch to PDF points with `Unit::PT` if you prefer.
- **Graphics** - lines, rectangles, circles, paths, fill / stroke, dashed lines, line caps and joins, transforms (translate, rotate, scale), save / restore graphics state.
- **Text** - the 12 standard PDF fonts (Helvetica, Times, Courier in regular / bold / italic / bold-italic), multi-line text with `\n`, configurable leading. Western Latin scripts including accents and the typographic characters `EUR`, `oe`, `OE`, `%.` etc.
- **Custom TrueType / OpenType fonts** - register `.ttf` or `.otf` files for the document (regular / bold / italic / boldItalic variants) and use them like the built-in fonts. Full Unicode reach: Latin Extended, Greek, Cyrillic, CJK, etc. Copy-paste from the rendered PDF works correctly. Fonts are automatically subsetted to the glyphs used, so even multi-megabyte CJK families produce small PDFs.
- **Cells** - rectangles with text, borders (per-side, configurable width / color / style: solid / dashed / dotted), fill color, padding, text alignment (left / center / right * top / middle / bottom), three width-fit modes (none / condense / shrink), automatic word-wrap, automatic force-break for words wider than the cell.
- **Text measurement** - `$page->stringWidth(...)` returns the exact width of any string in the current font.
- **Images** - JPEG and PNG (RGB / Gray / Palette / RGB+Alpha / Gray+Alpha) with transparency support. Auto-format detection. Same image used N times = one embed, N placements (per-document caching). Cursor flow via `NextPosition` (RIGHT / NEWLINE / BELOW / NONE) for sequential placement.
- **SVG vector images** - inline `<svg>` or `.svg` file, fully vector (infinite zoom). Shapes, paths (all commands including arcs), transforms, groups, `<use>` references, `viewBox` + `preserveAspectRatio`, solid fills and strokes with opacity, dash patterns, 147 named CSS colors, linear and radial gradients (per-stop alpha and spreadMethod pad / reflect / repeat), `<pattern>` tiling, `<clipPath>` clipping, `<mask>` luminance soft masks, `<symbol>` / `<marker>`, `<style>` CSS (with cascade), embedded raster `<image>` (PNG / JPEG data URI), and `<text>` / `<tspan>` / `<textPath>` rendered as real selectable text with the standard fonts or any registered custom TTF / OTF family (matched by `font-family`, with automatic glyph subsetting). `<filter>` is rendered via a hybrid pure-PHP raster (feGaussianBlur, feOffset, feFlood, feMerge, feBlend, feComposite, feColorMatrix, feDropShadow; resolution tunable via `Document::setSvgFilterResolution()`), with text in the filtered subtree kept sharp on top. Heavier filter primitives (feTurbulence, feDisplacementMap, lighting, etc.), scripts, and animations are skipped silently.
- **Barcodes & QR codes** - EAN-13, EAN-8, Code 128 (auto A/B/C set switching), UPC-A, Code 39, Code 93, ITF (Interleaved 2 of 5), QR Code (V1-V40 full ISO 18004 range, all four error-correction levels), Aztec Code (ISO/IEC 24778, Compact 1-4 layers and Full Range 1-32 layers, four EC presets, auto UTF-8 ECI), DataMatrix (ISO/IEC 16022 ECC200 squares 10x10 to 144x144, auto UTF-8 ECI), PDF417 (ISO/IEC 15438 standard variant, auto UTF-8 ECI). Pure-PHP encoders, vector rendering, configurable color, optional human-readable text under 1D codes, and optional vertical rendering of any 1D code via `->vertical()`.
- **Bookmarks & hyperlinks** - build a sidebar table of contents with nested sections (what PDF viewers show in their left panel) and place clickable areas anywhere on a page that open a URL or jump to another page in the same document. Declarative API.
- **Interactive forms** - the reader can type into the PDF before saving or printing it: text fields (single or multi-line, including password fields), checkboxes, radio buttons (grouped), dropdowns, listboxes, push buttons (resetForm, openUrl, and submit field data to a URL in FDF / HTML / XFDF / PDF format), and signature fields (visible or invisible) that can be left as placeholders or signed programmatically with a real PKCS#7 / CMS signature via `Document::sign()` and a PKCS#12 credential. Signatures can carry an RFC 3161 signature timestamp by passing a `Tsa` to `sign()` (`timestamp: Tsa::http('https://tsa.example/tsr')`), which embeds the Time Stamping Authority token as an unsigned attribute in the CMS for a trusted signing time independent of the signer's clock. (The default container is `adbe.pkcs7.detached`; pass `format: SignatureFormat::EtsiCadesDetached` to `sign()` / `addSignature()` for the strict PAdES profile - `/SubFilter /ETSI.CAdES.detached` with a CMS carrying the `signingCertificateV2` signed attribute, i.e. PAdES-B-B, or B-T with a `Tsa`. RSA keys.) A whole-document timestamp can be added as an incremental revision with `$doc->addDocumentTimestamp(Tsa::http($url))` (a `/DocTimeStamp`, `ETSI.RFC3161`), standalone or layered over a signature to attest the signed bytes; this is the building block toward PAdES-B-LT (LTV is a later phase). Several signers can each approve the same document: `$doc->addSignature($cred, reason: 'Reviewed')` adds an approval signature in its own incremental revision (so each signature covers all prior ones); chain `sign()` + N x `addSignature()` + an optional `addDocumentTimestamp()` for a fully layered file. Each field can be styled with border color and width, background color, text color, font, size, and alignment - plus per-field visibility flags (`hidden`, `noExport`) and advanced border styles (SOLID / DASHED / BEVELED / INSET / UNDERLINE via `FieldBorderStyle`). Text fields, comboboxes, listboxes, and checkboxes accept a `defaultValue` decoupled from their display `value`, restored by a ResetForm button. Page tab order is settable via `Page::setTabOrder(TabOrder::ROW | COLUMN | STRUCTURE)`. Text fields, comboboxes, and listboxes can carry JavaScript actions for auto-calculation (sum, product, average, min, max), display formatting (number, currency, percent, date, time), and input validation (range checks) - executed by Adobe Reader / Acrobat only. Document-level scripts run on open via `addDocumentScript`. Several fields sharing the same name are automatically linked - they emit as one logical field and stay synchronized in the reader (field linking).
- **Markdown** - render a CommonMark core subset (headings, paragraphs, bold / italic / inline code, links, images, ordered + unordered nested lists, fenced / indented code blocks, block quotes, thematic breaks) either flowing from the cursor with automatic page breaks via `Page::markdown()` or inside an auto-sized cell via `cell(markdown: true)`. Styling is configurable through `MarkdownStyle`.
- **Tables** - render data tables with `Page::table()`: fixed or `fill` column widths, automatic page-break with the header row repeated on each page (toggle via `TableStyle::withRepeatHeader()`), zebra striping, configurable borders (grid / horizontal rules / header underline / none), per-column alignment and padding, a conditional per-cell style callback, and cells holding either text or an image (e.g. avatars). Built on the existing cell/image pipeline.
- **PDF/A archival conformance** - emit ISO 19005-2 conformant files (PDF/A-2b and PDF/A-2u) with one call: `$doc->enablePdfA(PdfALevel::A2B)`. This embeds an sRGB output intent, stamps the XMP packet with the PDF/A identification schema, and always writes the document metadata and `/ID`. Every font must be embedded - register one with `registerFontFamily()`; using a non-embedded standard font (or encryption, or document JavaScript) throws. Validated against veraPDF, the ISO reference validator. PDF/A-3b and PDF/A-3u are also supported: call `$doc->attachFile($xmlBytes, 'factur-x.xml', AFRelationship::Data, 'text/xml')` to embed an associated file (e.g. a Factur-X / ZUGFeRD e-invoice XML). Attachments are rejected at PDF/A-2 (use A-3).

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

- [Getting Started](https://github.com/dragonofmercy/php-pdf/wiki/Getting-Started)
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
