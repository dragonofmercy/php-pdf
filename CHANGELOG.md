# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- PDF/A-1 conformance (ISO 19005-1:2005, PDF 1.4-based) via `enablePdfA(PdfALevel::A1B)` (level B)
  and `enablePdfA(PdfALevel::A1A, 'en-US')` (level A, requires a catalog language and tagged
  content via the high-level API). PDF/A-1 emits a `%PDF-1.4` header. It forbids transparency
  entirely: adding a PNG with an alpha channel, an SVG using fill/stroke opacity below 1.0, or
  an SVG using a mask, soft-mask, or filter throws a `PdfException` at output time (remedy:
  flatten against a solid background or use PDF/A-2 or higher). Embedded files are also
  forbidden (no part-3 equivalent). Both flavours are veraPDF-validated.
- PDF/A-4 conformance (ISO 19005-4:2020, PDF 2.0-based) via `enablePdfA(PdfALevel::A4)`
  and the `A4F` flavour for documents that embed arbitrary files. PDF/A-4 emits a
  `%PDF-2.0` header, carries its identification as `pdfaid:rev` (2020) in XMP, and -
  unlike parts 2-3 - omits the deprecated `/Info` trailer dictionary, keeping all
  metadata in the XMP stream. `A4` requires Unicode mapping but not tagging; `A4F`
  additionally permits embedded files (e.g. Factur-X / ZUGFeRD) and adds the
  `pdfaid:conformance` letter `F`. Both flavours are veraPDF-validated.

## [1.9.0] - 2026-06-11

### Added
- 13 new standard page formats on `PageFormat`: ISO A series `A0`, `A1`, `A2`, `A7`;
  ISO B series `B4`, `B5`; ISO C envelopes `C4`, `C5`, `C6`, `DL`; and North American
  `TABLOID` (Ledger, 11x17 in), `EXECUTIVE`, and `HALF_LETTER`. Usable anywhere a
  format is accepted (e.g. `addPage(PageFormat::A2)`); dimensions are exposed in
  portrait via `PageFormat::dimensionsMm()`.

## [1.8.0] - 2026-06-11

### Added
- Sign an existing PDF opened with `PdfEditor::open()` / `PdfEditor::fromBytes()`: `sign()`,
  `addSignature()`, `addDocumentTimestamp()`, and `enableLtv()` with full parity to
  `Document` (RFC 3161 timestamps, the PAdES profiles, and LTV/DSS with CRL/OCSP),
  each written as a stacked incremental revision that leaves the original bytes
  intact and cryptographically covers all prior edits. Reuses an existing empty
  signature field by name or creates a new one - invisible by default, or visible
  with a Helvetica text caption via `SignatureAppearance`. Signing combines with
  metadata / appended-page / field-fill edits (written as the first revision and
  covered by every signature). Validated with openssl, qpdf, and pyHanko
  (signature intact, valid, covering the entire file). Limitations: visible
  captions use Standard-14 Helvetica only; a source whose `/AcroForm` is a direct
  (inline) dictionary is rejected (indirect `/AcroForm` references only).
- AcroForm field reading and filling via `PdfEditor::open()` / `PdfEditor::fromBytes()`:
  inspect every terminal field with `formFields()` (returns `list<FormFieldInfo>`)
  or `field($name)`, then fill text (single-line and multiline), checkbox (bool),
  radio (export-name string), combobox (export key), and listbox (string or
  `list<string>` of export keys) with `setField($name, $value)`. The filled
  output is written as an appended incremental revision; each filled field
  receives a generated `/AP` appearance stream so the value is visible without
  viewer-side rendering. `/AcroForm` is re-emitted with `/NeedAppearances false`
  so conforming viewers trust the generated appearances. Limitation: appearance
  generation supports Standard-14 `/DR` fonts only (fields that reference an
  embedded font throw). Fields remain interactive (no flattening).
- Template import: `Document::importPdf()` / `importPdfBytes()` parse an existing
  PDF and `Page::template($tpl, $x, $y, $width, $height)` draws any of its pages
  as an opaque background or stamp (FPDI-style) - letterheads, overlays on scanned
  forms, watermarking. Page rotation is honored, resources are carried over with
  renumbering, and templates work together with encryption, tagging (marked as
  artifacts), and signatures. Encrypted source files are rejected.
- PDF reader foundation (`PdfReader`): parse existing non-encrypted PDFs -
  classic xref tables and cross-reference streams (with /Prev revision chains
  and hybrid-reference files), object streams, FlateDecode (PNG/TIFF
  predictors) / ASCIIHexDecode / ASCII85Decode / RunLengthDecode filters, lazy
  object resolution with offset-recovery leniency, and page-tree access with
  inherited attributes (`PdfReader::fromFile()`, `pageCount()`, `page()`).
  Groundwork for template import and incremental modification of existing
  files; encrypted input is rejected with a clear error.
- Modify existing PDFs: `PdfEditor::open($path)` / `PdfEditor::fromBytes($bytes)` open any
  non-encrypted PDF and write changes as an appended incremental revision (the
  original bytes - and any signatures they carry - stay untouched): update
  metadata (`setTitle` / `setAuthor` / `setSubject` / `setKeywords` /
  `setCreator`, with the XMP stream refreshed when present) and append new
  pages with the full page API (`appendPage()`). Works on classic xref tables
  AND cross-reference streams (the appended revision matches the source
  format, as ISO 32000-1 requires). AcroForm filling and signing existing
  files build on this next.

## [1.7.0] - 2026-06-09

### Added
- Right-to-left text support (Phase A): Unicode bidirectional algorithm reordering for Hebrew on cells and tables, with `Document::setBaseDirection()`, a per-`cell()` `direction:` argument, `Cell::direction()` for table cells, and the `Direction` enum. RTL base direction right-aligns by default. Byte-identical output when no RTL text is present.
- Arabic shaping: contextual presentation forms (isolated/initial/medial/final)
  and mandatory lam-alef ligatures for `cell()` and table cells, in pure PHP.
  Requires a font whose cmap contains the Arabic presentation forms.
- RTL Markdown: bidi reordering and Arabic shaping in `Page::markdown()` and
  `cell(markdown: true)`, with right-aligned RTL blocks and mirrored list
  markers / blockquote bars. Opt in via the `direction:` argument or
  `Document::setBaseDirection()`.
- Inter-document TTF parse cache: parsed custom fonts are memoized in-process
  and shared across every `Document`, so repeated PDF generation with the same
  fonts no longer re-reads or re-parses the font files. The cache is transparent
  and leaves output byte-identical; on shared web hosting (mod_php / php-fpm)
  PHP static state resets between requests, so it only accumulates in
  long-lived CLI batch jobs and queue workers. Call `ParsedTtfCache::clear()`
  to reset it in a long-running worker.

## [1.6.0] - 2026-06-08

### Added
- Tagged PDF (accessibility): `Document::enableTagging(?string $lang = null)`
  opts a document into tagged-PDF output and, optionally, sets the document
  `/Lang` from a BCP-47 language tag. When enabled, the high-level API is
  tagged automatically (`cell()` text into `<P>`, `image()` into `<Figure>`,
  `table()` into `<Table>` / `<TR>` / `<TH>` / `<TD>`, and `markdown()` blocks
  into `<H1>`..`<H6>`, `<P>`, and `<L>` / `<LI>` / `<LBody>`): the library
  builds a logical structure tree and emits the catalog `/StructTreeRoot`,
  `/MarkInfo`, ParentTree, per-page `/StructParents`, and `/Tabs /S`, wrapping
  page content in MCID-keyed marked-content sequences. It is opt-in and off by
  default - output is byte-identical when `enableTagging()` is not called.
  `enableTagging()` alone produces a well-formed structure tree but not a
  conformant one; for PDF/UA-1 conformance use `enablePdfUA()` (below).
- PDF/UA-1 conformance: `Document::enablePdfUA(?string $lang = null)` builds on
  tagging to produce output that validates `isCompliant` under the veraPDF
  PDF/UA-1 profile for documents built from `cell()` / `table()` / `markdown()`
  / `image()`. It marks decoration (cell fills and borders, table chrome,
  Markdown list markers, header and footer content) as `/Artifact`; carries
  alternate text on figures via `image(alt: '...')`, with `image(decorative:
  true)` to mark a purely decorative image as an artifact instead; gives table
  header cells a `/Scope /Column` attribute; sets `/ViewerPreferences
  /DisplayDocTitle true`; always emits an XMP `/Metadata` stream carrying the
  `pdfuaid:part` identifier; and runs a fail-fast conformance guard that
  requires every font to be embedded and a document title to be set, and rejects
  figures without alternate text and skipped heading levels.
- PDF/UA-1 tagged hyperlinks: `cell(text:, link:, linkAlt:)` draws a text cell,
  makes it a clickable link annotation over the cell box, and - under tagging -
  tags it as a `<Link>` structure element containing the text and an `/OBJR` to
  the annotation, with the annotation carrying `/StructParent`, `/Contents`
  (from `linkAlt`, defaulting to the cell text), and the Print flag. Such links
  validate under veraPDF PDF/UA-1. The low-level `Page::link()` area link stays
  untagged and is rejected by the conformance guard under `enablePdfUA()` (use
  `cell(link:)` for accessible links).
- Markdown inline hyperlinks are now tagged as `<Link>` structure elements (OBJR + /StructParent + /Contents, underline marked as /Artifact) when tagging is enabled, so Markdown containing links is PDF/UA-1 conformant. A link wrapping across lines is a single `<Link>` with one rectangle per line. With tagging off the output is unchanged.
- Image hyperlinks are now tagged as `<Link>` structure elements wrapping the image `<Figure>` (OBJR + /StructParent + /Contents) when tagging is enabled, via `Page::image(link: ..., linkAlt: ...)` and Markdown block image links `[![alt](img)](url)`, so documents with image links are PDF/UA-1 conformant. Markdown block images now also carry their alt text as `/Alt`.
- PDF/A conformance level A: `enablePdfA(PdfALevel::A2A)` and `A3A` emit PDF/A-2a / PDF/A-3a (level A = the Unicode requirements plus a tagged logical structure tree). Level A auto-enables tagging; pass the catalog language as the second argument, e.g. `enablePdfA(PdfALevel::A2A, 'en-US')`, and draw content through the tagged high-level API. Combining it with `enablePdfUA()` produces a single file that is both PDF/A-2a and PDF/UA-1 (validated against veraPDF 2a and ua1). This completes PDF/A level support (B, U, A across parts 2 and 3).

## [1.5.0] - 2026-06-04

### Added
- Table cell spanning: `Cell::colSpan($n)` spans a data cell across `$n`
  adjacent columns (covered column keys in the row are ignored; border and fill
  cover the merged width). `TableStyle::withColumnGroups(ColumnGroup ...)` adds a
  grouped-header band above the per-column header row, where each `ColumnGroup`
  labels a span of columns; `ColumnGroup::spacer()` lets a standalone column's
  header rise across both bands. Groups must cover every column exactly. Vertical
  spanning (rowspan) is not yet supported.
- Multi-column text flow: `Page::columns($count, gap, fill, render)` runs a
  scoped block in which `cell()` and `markdown()` wrap to the column width and
  fill each column top-to-bottom before advancing to the next column, then to a
  new page (sequential fill, independent of `setAutoPageBreak`). `Page::columnBreak()`
  forces the next column. Equal-width columns; `ColumnFill::BALANCED` is reserved
  (throws until implemented); images / barcodes / tables are rejected inside the
  block in this version.
- Text justification: `TextAlign::JUSTIFY` fills each wrapped line to the cell's
  inner width by distributing the surplus space across inter-word gaps, for both
  `Page::cell()` and `Page::table()` columns. The last line of each paragraph
  stays left-aligned (standard typographic behaviour). Works for standard and
  custom (embedded) fonts alike via a font-agnostic `TJ` array; auto-width cells
  and the condense / shrink fit modes fall back to left alignment.

## [1.4.2] - 2026-06-04

### Fixed
- Composer dist archive no longer ships development-only files. Previously only
  `examples/` was excluded, so `composer require` pulled `tests/`, `.github/`,
  `bin/`, `phpunit.xml`, `phpstan.neon`, `composer.lock` and the dotfiles into
  `vendor/`. These are now `export-ignore`d; `src/`, `resources/` (the runtime
  ICC profile), `composer.json`, `LICENSE`, `README.md` and this changelog still
  ship.

## [1.4.1] - 2026-06-04

### Fixed
- `Page::markdown()` and `Page::table()` now advance the cursor below the
  rendered block by default (`$ln` defaults to `NextPosition::BELOW` instead of
  `NextPosition::NONE`). Consecutive flowing calls now stack down the page
  naturally instead of overwriting each other. Pass `ln: NextPosition::NONE` to
  keep the cursor in place. `Page::barcode()` is unchanged, and
  `TableResult::$y` still reports the position just below the last row.

## [1.4.0] - 2026-06-03

### Added
- Images: `Page::image()` now takes a `NextPosition $ln` parameter so images
  advance the page cursor after drawing, like `cell()` and `barcode()`. The
  default is `RIGHT` (cursor moves to the right edge, y unchanged), which
  preserves the prior behaviour; `NEWLINE`, `BELOW`, and `NONE` are also
  available. An explicit `x` sets the row's line-start so a following `NEWLINE`
  returns to it. `image()` does not auto-page-break.

## [1.3.0] - 2026-06-03

### Added
- Tables: `Page::table($columns, $rows, ...)` renders data tables on top of the
  existing cell/image pipeline. Columns are fixed-width or `fill` (sharing the
  remaining width by weight). Long tables auto-page-break with the header row
  repeated on each page (`TableStyle::withRepeatHeader()`). Styling: configurable
  borders (`TableBorders` GRID / HORIZONTAL / HEADER_UNDERLINE / NONE), header
  fill/bold/color, zebra striping, a conditional per-cell style callback, and
  per-column alignment/padding. A cell may hold text or an image (`Cell::image()`,
  e.g. avatars). No cell spanning in this version. New `DragonOfMercy\PhpPdf\Table\`
  namespace: `Column`, `Cell`, `CellStyle`, `TableStyle`, `TableBorders`, `TableResult`.

## [1.2.0] - 2026-06-03

### Added
- PDF/A-2 archival conformance (`Document::enablePdfA(PdfALevel::A2B|A2U)`): sRGB output intent, `pdfaid` XMP schema, forced metadata + document `/ID`, with a conformance guard that rejects non-embedded standard fonts, encryption, document JavaScript, and appended revisions. Validated with veraPDF.
- PDF/A-3 archival conformance with embedded files: `Document::attachFile(...)` plus the `AFRelationship` enum, emitting `/AF` and an `/EmbeddedFiles` name tree (Factur-X / ZUGFeRD use case). Validated with veraPDF (flavour 3b).
- Long-term validation (LTV) for signatures: `Document::enableLtv()` embeds the
  signer certificate chain plus revocation data in a Document Security Store
  (`/DSS`) and, when given a `Tsa`, covers them with a `/DocTimeStamp`, so
  signatures remain verifiable after the signer certificate expires. Revocation
  is embedded as CRLs (`/CRLs`) or OCSP responses (`/OCSPs`). Validation material
  is supplied through an injectable `ValidationDataSource`: `HttpCrlValidationDataSource`
  fetches CRLs from each certificate's distribution point, `HttpOcspValidationDataSource`
  fetches OCSP responses from each certificate's AIA responder, and
  `StaticValidationDataSource` supplies material the caller already obtained
  (offline use). The per-signature `/VRI` entry is a later phase.
- Strict ETSI.CAdES signature profile: `sign(..., format: SignatureFormat::EtsiCadesDetached)`
  (and the same on `addSignature()`) emits `/SubFilter /ETSI.CAdES.detached` with
  a hand-built CMS carrying the `contentType`, `messageDigest` and
  `signingCertificateV2` (ESS, RFC 5035) signed attributes - PAdES-B-B, or B-T
  when combined with a `Tsa`. The default stays `adbe.pkcs7.detached`. RSA keys.
- PAdES-B-LTA: `enableLtv($source, $timestamp, $timestampCertificateChains)`
  embeds the document-timestamp TSA certificate's chain plus revocation in the
  DSS, so the archive timestamp is itself long-term validatable (validated
  end-to-end with pyHanko: signature and document timestamp both verify from the
  embedded DSS under hard-fail). Archive-timestamp renewal remains a later phase.

## [1.1.0] - 2026-06-02

### Added
- SVG `<filter>`: `filter="url(#id)"` on any element renders through a deterministic pure-PHP raster of the filter region (embedded as a DeviceRGB image plus a DeviceGray `/SMask`), while text in the filtered subtree is redrawn sharp (vector) on top. First-wave primitives: `feGaussianBlur`, `feOffset`, `feFlood`, `feMerge`, `feBlend` (normal / multiply / screen / darken / lighten), `feComposite` (over / in / out / atop / xor / arithmetic), `feColorMatrix` (matrix / saturate / hueRotate / luminanceToAlpha), and `feDropShadow`. Filters run in linearRGB. Raster resolution is tunable via `Document::setSvgFilterResolution(int $dpi)` (default 300, capped at 2000 px per side). `ext-gd` stays optional (only used to decode a JPEG referenced inside a filter; PNG decodes with the built-in decoder). Heavier primitives (`feTurbulence`, `feDisplacementMap`, `feConvolveMatrix`, `feMorphology`, `feImage`, `feTile`, lighting) remain out of this wave.
- Markdown rendering of a CommonMark core subset via two surfaces: `Page::markdown()` flows text from the cursor and auto-paginates when the document has auto-break enabled (otherwise it renders atomically on the current page), and `cell(markdown: true)` renders the cell text as Markdown into the cell's inner box while auto-sizing its height.
- Supported constructs: ATX headings, paragraphs, inline bold / italic / bold+italic / inline code, links, images (local file path or `data:` URI), ordered and unordered nested lists, fenced and indented code blocks, block quotes, and thematic breaks. Out-of-scope constructs (tables, reference links, footnotes, task lists, raw HTML, setext headings, syntax highlighting, autolinks) are skipped silently or rendered as literal text.
- Configurable `MarkdownStyle` value object (`MarkdownStyle::default()` plus immutable withers for heading sizes, body size, paragraph spacing, code font and background, link color and underline, block-quote bar, and list indent).
- Custom TTF / OTF fonts in SVG `<text>` / `<tspan>`: a `font-family` token matching a family registered via `Document::registerFontFamily()` (case-insensitive, custom families take precedence over the standard keyword map, Helvetica as final fallback) now renders with that custom face, with full Unicode reach and automatic glyph subsetting. A requested bold / italic variant that was not registered falls back to the registered regular face of the same family, never to a standard font. Works with all existing SVG text features (text-anchor, multiline, dx / dy, fill + stroke, opacity, transform, inheritance).
- RFC 3161 signature timestamping: pass a `Tsa` to `Document::sign()` (`timestamp: Tsa::http($url)`, optional HTTP Basic auth and SHA-256 / 384 / 512). The TimeStampToken from the Time Stamping Authority is embedded as an `id-aa-timeStampToken` unsigned attribute inside the signature CMS, providing a trusted signing time independent of the signer's clock. A `TsaClient` interface (default `HttpTsaClient`) is injectable for custom transports and testing. The container stays `adbe.pkcs7.detached`; the strict PAdES profile (`ETSI.CAdES.detached`) remains a later phase.
- Document timestamps: `$doc->addDocumentTimestamp(Tsa::http($url))` appends an incremental PDF revision carrying a `/DocTimeStamp` (`ETSI.RFC3161`), standalone or layered over a `sign()` signature so the timestamp covers the signed bytes. Built on a new incremental-update writer (stacked revisions with a `/Prev` trailer and subsectioned xref). Reuses the RFC 3161 TSA client. Applies to documents phppdf generates (no external-PDF parser).
- Multiple approval signatures: `$doc->addSignature($cred, reason:, location:, contactInfo:, signedAt:, maxSignatureBytes:, timestamp:)` adds an approval signature in its own incremental revision, so each signature cryptographically covers all prior revisions. Chains with `sign()` and `addDocumentTimestamp()`. Each appended signature can itself carry an RFC 3161 signature timestamp. The `/Contents` byte-surgery and the appended-revision builder are now shared across signatures and timestamps.
- SVG `<textPath>`: text laid along a referenced `<path>`, with per-glyph rotation to the path tangent, `startOffset` (absolute or percentage), and `text-anchor`. Standard and registered custom fonts are supported (with glyph subsetting); `<tspan>`s inside `<textPath>` keep their own fill/style.

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

[1.9.0]: https://github.com/dragonofmercy/php-pdf/releases/tag/v1.9.0
[1.8.0]: https://github.com/dragonofmercy/php-pdf/releases/tag/v1.8.0
[1.7.0]: https://github.com/dragonofmercy/php-pdf/releases/tag/v1.7.0
[1.6.0]: https://github.com/dragonofmercy/php-pdf/releases/tag/v1.6.0
[1.5.0]: https://github.com/dragonofmercy/php-pdf/releases/tag/v1.5.0
[1.4.2]: https://github.com/dragonofmercy/php-pdf/releases/tag/v1.4.2
[1.4.1]: https://github.com/dragonofmercy/php-pdf/releases/tag/v1.4.1
[1.4.0]: https://github.com/dragonofmercy/php-pdf/releases/tag/v1.4.0
[1.3.0]: https://github.com/dragonofmercy/php-pdf/releases/tag/v1.3.0
[1.2.0]: https://github.com/dragonofmercy/php-pdf/releases/tag/v1.2.0
[1.1.0]: https://github.com/dragonofmercy/php-pdf/releases/tag/v1.1.0
[1.0.0]: https://github.com/dragonofmercy/php-pdf/releases/tag/v1.0.0
