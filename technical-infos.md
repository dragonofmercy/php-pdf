# Technical reference

This document covers how phppdf works under the hood: PDF object structure, text encodings, font embedding strategies, image and SVG rendering, AcroForm appearances, encryption, etc. Read [README.md](README.md) first for the public API.

## Architecture

**Fluent builder, deferred serialization.** `Document` accumulates pages, fonts, images, encryption settings, and metadata in memory. Nothing is written to bytes until `output()` (or `save()`) is called; that single call serializes everything in one deterministic pass via `Writer/PdfWriter` and the indirect-object infrastructure in `Writer/Object/`.

The serialization pass produces a PDF 1.7 file with:

- A cross-reference table (`xref`) listing every indirect object by byte offset.
- A trailer pointing at the catalog and the metadata.
- Compressed content streams (FlateDecode).
- Deterministic object numbering: the same input always produces the same bytes (which is what makes the golden-tests harness possible).

**Units.** All public coordinates default to millimetres; switch to PDF points via `new Document(Unit::PT)`. Font sizes always stay in points (typographic convention). Internally, every measurement is converted to points and stored as `$xxxPt` floats. The Y axis is **top-down** in the public API; conversion to PDF native (bottom-up) happens at serialization time.

**Stateful Page, single-pass content stream.** `Page` owns a `ContentStream`, a font / size / leading state machine, an `(x, y)` cursor, and the `inHeaderRender` flag. `cell()` is the workhorse: it normalizes newlines, optionally triggers auto-page-break via `Document::addPage()`, then delegates rendering to `Page/CellRenderer`. The cursor is driven by the `NextPosition` enum (RIGHT / NEWLINE / BELOW).

**Page lifecycle.** `Document::setMargins`, `setHeader`, `setFooter`, `setAutoPageBreak`. Headers fire eagerly at `addPage()`; footers fire **deferred** at `output()` to allow correct `(pageNumber, totalPages)` substitution. Footers are guarded by `$footersRendered` so repeated `output()` calls do not double-emit. The `$inHeaderRender` flag on Page doubles as auto-break suppressor during header rendering and as a one-shot recursion guard for cells larger than the drawable area.

## Text encoding

phppdf uses two different text encodings depending on the font:

### WinAnsi (standard PDF fonts)

The 12 built-in fonts (Helvetica / Times / Courier x Regular / Bold / Italic / BoldItalic) use the **WinAnsi** encoding (CP1252). It covers:

- ASCII (0x20-0x7E).
- Latin-1 supplement (0xA0-0xFF) - accents, common Latin symbols.
- The typographic characters in the 0x80-0x9F range: `EUR`, `oe`, `OE`, smart quotes, em-dash, en-dash, ellipsis, etc.

Characters outside WinAnsi (Greek, Cyrillic, CJK, etc.) cannot be rendered with the standard fonts. Register a custom font instead.

The 12 AFM-derived metric files in `src/Font/Metrics/` are generated from Adobe Type 1 AFM source files by `bin/generate-font-metrics.php` and contain the per-glyph widths used by `stringWidth()` and the cell wrapper.

### Identity-H (custom fonts)

Registered TTF / OTF fonts use **Identity-H** encoding: each Unicode code point is mapped directly to its glyph index in the font. This is what makes full Unicode reach possible (Latin Extended, Greek, Cyrillic, supplementary planes including CJK).

The technical structure for a custom font is:

- A composite font dictionary of subtype `/Type0` with `/Encoding /Identity-H` and a `/DescendantFonts` pointing at...
- A `CIDFontType2` (for TTF) or `CIDFontType0` (for OTF / CFF) dictionary describing the embedded font.
- An embedded `/ToUnicode` CMap stream that maps glyph indices back to Unicode code points - this is what makes **copy-paste from the rendered PDF work correctly**.

### cmap subtable support

When parsing a TTF / OTF, phppdf reads the `cmap` table looking for:

- **Format 4** (BMP, U+0000 to U+FFFF) - the most common format for Western and basic CJK fonts.
- **Format 12** (full Unicode, including supplementary planes U+10000+) - required for emoji, ancient scripts, and the full CJK extension blocks.

Other subtable formats are skipped. If neither format 4 nor format 12 is present, registration throws `PdfException`.

## Font subsetting

Both TTF and OTF / CFF fonts are automatically **subsetted**: the embedded font in the PDF only contains the glyphs your document actually uses, not the full original file. This is what keeps PDFs small even when you use a 10 MB CJK font family.

The subsetting strategy is **GID-preserving**:

- The Identity-H encoding maps Unicode -> GID directly. If the subsetter renumbered glyphs, every text-showing operator in every content stream would also need rewriting.
- GID-preserving means: keep the original glyph indices, just remove the data for unused glyphs (zero-out unused `glyf` entries for TTF, drop unused CharStrings for CFF).
- For TTF: the `glyf` and `loca` tables are rebuilt with empty entries for unused GIDs.
- For OTF / CFF: the CFF table is parsed, the CharStrings INDEX is rewritten to keep only used glyphs (or, for CID-keyed CJK fonts, only used CIDs), the FDSelect / FDArray are pruned, and the CFF blob is re-emitted.

Subsetting runs at `output()` time, after the full document has accumulated which glyphs are used.

### Not implemented

- TrueType Collections (`.ttc`) - a `.ttc` is a container with multiple fonts; not supported.
- Variable fonts (`fvar` / `gvar`) - not supported.
- Kerning (`kern` table or GPOS) - all glyph advances are based on the un-kerned widths.
- Ligatures and complex shaping (GSUB) - no Latin `fi` / `fl` ligatures, no Arabic / Indic shaping, no Devanagari reordering.
- Right-to-left direction.
- Identity-V (vertical writing).

## Images

JPEG and PNG flow through `Image::fromFile()` / `Image::fromBytes()` / `Image::fromBase64()` into a single internal pipeline:

- JPEG: embedded as-is with `/Filter /DCTDecode`. Supports RGB, Gray, and CMYK color spaces.
- PNG (RGB / Gray / Palette): the IDAT chunks are concatenated and re-emitted as `/Filter /FlateDecode` with the appropriate `/DecodeParms` (predictor 15 with the original BitsPerComponent / Colors / Columns).
- PNG with palette: the PLTE chunk is emitted as an `/Indexed` color space.
- PNG with alpha (RGB+Alpha / Gray+Alpha / Palette + tRNS): the alpha channel is **split** into a separate single-component grayscale XObject and attached to the main image via `/SMask` (soft mask). The reader composites them on render.

Each image is registered in the document as a Form-like XObject (`/Type /XObject /Subtype /Image`) and referenced by name in the content stream. **Caching is path-based and instance-based**: the same path string used N times reuses one embed; the same `Image` instance reused N times reuses one embed.

## SVG

SVG inputs (`<svg>...</svg>` or `<?xml ... <svg>`) are embedded as **Form XObjects** (`/Type /XObject /Subtype /Form`) rather than as raster images, so they remain vector at any zoom.

The pipeline is:

1. XML parsed via `libxml`.
2. Limits enforced: max 5 MiB raw, max nesting depth 32, max 50 000 elements, no cycles in `<use>` references, root must be `<svg>` in the SVG namespace, `viewBox` or width / height required.
3. SVG painter state walked depth-first, emitting a PDF content stream into a new Form XObject.
4. `viewBox` + `preserveAspectRatio` resolved to a `cm` (concat-matrix) operator at the Form XObject's start, so the embedded coordinate space maps correctly onto the placement rectangle.
5. Cached per-document like raster images.

### Opacity rendering

SVG `fill-opacity`, `stroke-opacity`, and `opacity` (which multiplies both) are emitted as **graphics state parameter dictionaries** (`/ExtGState` entries):

- A `ca` value for fill alpha.
- A `CA` value for stroke alpha.
- One `/ExtGState` per unique alpha pair, named `/Gs1`, `/Gs2`, etc., and referenced in the content stream via `gs` operators.

This is the standard PDF way to express partial transparency.

### Supported

- All path commands: M, L, H, V, C, S, Q, T, A, Z and their lowercase (relative) variants. Arcs are converted to cubic Bezier curves.
- Basic shapes: rect (with optional rx / ry rounded corners), circle, ellipse, line, polyline, polygon.
- Transforms: matrix, translate, scale, rotate (with optional center), skewX, skewY; composition left-to-right.
- viewBox + preserveAspectRatio (all 9 alignments x meet | slice; `none` stretches).
- Groups (`<g>`), `<defs>` + `<use>` references with cycle detection.
- Paint state: solid fill / stroke (147 named CSS colors, hex, `rgb()`, `rgba()`, `currentColor`), stroke-width, stroke-linecap, stroke-linejoin, stroke-miterlimit, stroke-dasharray + stroke-dashoffset, fill-rule (nonzero | evenodd), fill-opacity, stroke-opacity, opacity (multiplicative).
- Presentation attributes AND inline `style="..."`. Precedence: inline style > direct attribute > inherited.

### Not supported (skipped silently per SVG fallback spec)

- `<text>`, `<tspan>`, `<textPath>`. Workaround for logos: convert text to paths in your authoring tool.
- `<linearGradient>`, `<radialGradient>`, `<pattern>`. `fill="url(#x)"` falls back to black.
- `<filter>` and all `<fe*>` (blur, drop-shadow, etc.).
- `<mask>`, `<clipPath>`, embedded `<image>`, `<symbol>`, `<marker>`.
- External CSS via `<style>` blocks or external sheets.
- Scripts, animations, foreignObject.

## Outlines and hyperlinks

### Outline tree (Bookmarks panel)

The outline is a hierarchical doubly-linked tree of `/Type /Outlines` dictionaries with `/First`, `/Last`, `/Next`, `/Prev`, `/Parent`, and `/Count` pointers. phppdf builds this tree from the `Outline::add(...)` declarative API and serializes the cross-references at `output()` time.

Each outline item has a `/Dest` referencing a destination:

- `[page /XYZ left top zoom]` - page + position + zoom (the default for `Destination::page(0)` is XYZ at top-left).
- `[page /Fit]` - fit whole page in viewport.
- `[page /FitH top]` - fit page width.

### Link annotations

Each `$page->link(...)` call adds a `/Type /Annot /Subtype /Link` dictionary to the page's `/Annots` array. Two variants:

- URL link: `/A << /S /URI /URI (https://...) >>`.
- Internal jump: `/Dest [page ...]` using the same destination encoding as outline items.

The `/Border [0 0 0]` entry makes the link rectangle **invisible** - the standard "underline appears on click in Acrobat" behavior is what users expect, but the rectangle itself isn't outlined by default.

### Byte-identity preservation

Pages without any `link()` calls produce no `/Annots` array, so their content streams remain byte-identical to the equivalent page from earlier phases of the library. This is what allows the golden-test fixtures from Phase 1-6 to stay valid after Phase 7 shipped outlines.

## AcroForm interactive forms

AcroForm fields live both in the document catalog (as `/AcroForm`) and on individual pages (as widget `/Annots`). phppdf uses a **hybrid appearance strategy**:

### TextField, Combobox, Listbox - `NeedAppearances`

- The document catalog's `/AcroForm` dictionary gets `/NeedAppearances true`.
- The field declares a `/DA` (default appearance) string like `/Helv 12 Tf 0 g`, which sets the font and color.
- No `/AP` (appearance stream) is generated. The PDF reader is responsible for rendering the field on the fly when the file opens.
- This way, the displayed text always matches the field's current value (including user-edited values).

### Checkbox, Radio - pre-generated `/AP`

- For these, `NeedAppearances` is unreliable across readers (Acrobat draws boxes but Firefox PDF.js doesn't, etc.).
- phppdf pre-generates the `/AP` (appearance) streams for both `On` and `Off` states, embedding a small content stream that draws the tick mark or filled circle.
- The widget references these via `/AP << /N << /Yes 12 0 R /Off 13 0 R >> >>`.
- This guarantees identical rendering across Acrobat, browser viewers (Chrome PDF, Firefox PDF.js), headless PDF renderers, and others.

### Field styling

Optional per-field `FieldAppearance`:

- Border color + width: emitted as `/Border [0 0 W]` plus `/BS << /W ... /S /S >>` and `/MK << /BC [r g b] >>`. The `/Border` entry is what makes Edge / Firefox / Brave render the colored frame (some readers ignore `/BS` and only honor `/Border`, so we emit both).
- Background color: `/MK << /BG [r g b] >>`.
- Text color: encoded in the `/DA` string (`r g b rg` for fill).
- Font: Helvetica, Courier, or Times. Auto-registered in the form's default resources `/AcroForm /DR << /Font << /Helv 14 0 R /Cour 15 0 R /TiRo 16 0 R >> >>` only if used.
- Font size: encoded in `/DA` (`/Helv 12 Tf`).
- Alignment (TextField only): `/Q 0` (left), `/Q 1` (center), `/Q 2` (right).

### Byte-identity preservation

Pages without any `field()` calls produce:
- No extra `/Annots` entries (only what `link()` adds, if anything).
- No `/AcroForm` entry in the catalog.

So the byte-identity baseline of earlier phases is preserved.

## Encryption

phppdf supports two encryption schemes:

- **RC4-40** (V=1, R=2) - legacy 40-bit. Cracked in seconds on modern hardware, included for compatibility.
- **AES-128** (V=4, R=4) - 128-bit AES in CBC mode with a per-object initialization vector. Reasonably strong.

### How the encryption pass works

When `$document->encryption()` is configured, `output()` takes a different path via `EncryptedPdfWriter`:

1. The file's master key is derived once via `EncryptionKey::fileKey()` from the user / owner passwords, the document `/ID`, and the desired permissions bitmask. This computation matches the PDF 1.7 spec exactly.
2. For each indirect object being serialized, a **per-object key** is derived by hashing the file key with the object number and generation.
3. `ObjectTransformer` walks the object graph and rewrites every string (`(...)` or `<...>`) and every stream payload using that per-object key:
   - RC4: simple stream cipher applied byte-by-byte.
   - AES-128: PKCS#7 padding, random 16-byte IV prefixed to the ciphertext.
4. The trailer's `/Encrypt` entry references an encryption dictionary that declares the algorithm, key length, version (`V`), revision (`R`), permissions flags, and the encoded user / owner password hashes.

### Permissions

`Encryption::allowPrint()`, `allowCopy()`, `allowModify()`, etc. set bits in the `/P` permissions integer. These are **hints**: a viewer that respects the spec will gray out the print button if `allowPrint()` isn't set, but the underlying data is still readable to anyone who has the user password (or to anyone using a tool that ignores permission flags - they're not cryptographic, just advisory).

## Golden tests

Every fixture in `tests/Golden/fixtures/*.pdf` is **byte-compared** to a fresh render. Any change to rendering output - even apparently harmless refactors - breaks the test suite. This is intentional:

- It catches accidental output drift (e.g. a refactor that changes operator order, even when the output is visually identical).
- It documents intended output changes: when you do change rendering on purpose, you regenerate the fixtures and the diff in the commit shows exactly what changed.

Intentional output changes require running `php tests/Golden/regenerate.php` and committing the regenerated fixtures alongside the code change.

Each golden test also has a paired `qpdf --check` step that auto-skips when qpdf is not on PATH. `qpdf --check` validates structural integrity (xref table, stream lengths, object references), so even if a render is byte-identical to an old fixture, `qpdf` will catch a broken xref.

## Conventions used throughout the codebase

- PHP 8.4 features in use: `final readonly class`, typed class constants (`private const string FOO = '...'`), constructor property promotion, `Closure` parameter types, named arguments at call sites.
- Value objects are `final readonly` with named constructors (`Color::hex()`, `Border::all()`, `CellPadding::symmetric()`, etc.). Validation is eager in the constructor with messages that include the offending value: `"Cell padding top cannot be negative, got -1"`.
- Public API exposes float coordinates in the document unit. Internal helpers ending in `Pt` operate in points. Never mix.
- Error handling is fail-fast: throw `PdfException` (or subclass) at the boundary.
- PHPStan runs at `level: max` on `src/` and `tests/`. New code must pass with zero errors (no `@phpstan-ignore`); prefer adding a real accessor over silencing an `onlyWritten` property.
- PHPUnit is configured with `failOnWarning="true"` and `failOnRisky="true"`: a warning is a failure.
- ASCII only in code, comments, and commit messages (no em-dash, no curly quotes).
