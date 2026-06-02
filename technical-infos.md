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

### SVG gradients

`<linearGradient>` and `<radialGradient>` are painted as **PDF Shading Patterns** (`/PatternType 2`, shading type 2 for linear and type 3 for radial), each registered in the document's pattern registry and named `/Pn`. The content stream switches the color space to `/Pattern` before painting: `/Pattern cs ... /Pn scn` for fill and `/Pattern CS ... /Pn SCN` for stroke, so the existing path terminators (`f`, `S`, `B`, `f*`, `B*`) are reused unchanged.

**Pattern matrix.** A PDF pattern's `/Matrix` is relative to the form XObject's base coordinate system, not the current painting CTM. The renderer accumulates the full chain: viewBox prologue `cm` + group and shape transforms, then for `gradientUnits="objectBoundingBox"` the shape bounding-box affine map, then the gradient's own `gradientTransform`. The result is written as the pattern's `/Matrix`, giving correct placement regardless of nesting depth.

**Color function.** Two-stop gradients use a single FunctionType 2 (exponential, N=1) interpolating between the two endpoint RGB triples. Three or more stops use a stitching FunctionType 3 wrapping one FunctionType 2 per adjacent pair, with `Bounds` and `Encode` derived from the stop offsets. `Extend [true true]` on the shading dictionary implements the `pad` spread (colors clamp beyond the gradient ends).

**Stop opacity.** When all stops share the same `stop-opacity`, that value is folded into the existing ExtGState `ca` / `CA` mechanism (the same per-pair alpha dictionaries used for `fill-opacity` / `stroke-opacity`). When opacity varies across stops, the color shading is painted with alpha forced to 1 inside an outer Form XObject, and a parallel alpha shading (a `/DeviceGray` shading whose stop "colors" are the per-stop opacities) is sub-rendered into a luminance `/SMask` Form, reusing the soft-mask infrastructure. The alpha-shading `/Matrix` must NOT include the shape CTM (the color matrix does): the alpha shading lives in the SMask Form's user space while the color shading lives in the outer Form's post-viewBox space - two opposite `/Matrix` conventions.

**Spread methods.** `spreadMethod="reflect"` and `"repeat"` are implemented by rewriting the gradient: the geometry is extended and the stops are replicated outward in PAD mode (the `ShadingBuilder` itself is unchanged). For radial gradients the extent is measured from the center `(cx, cy)`, not the focal point `(fx, fy)`.

**href stop inheritance.** A gradient element that has `href` (or `xlink:href`) pointing to another gradient inherits its `<stop>` children from the target. Inheritance is resolved before rendering with cycle detection; a cycle causes the gradient to be skipped silently.

### Supported

- All path commands: M, L, H, V, C, S, Q, T, A, Z and their lowercase (relative) variants. Arcs are converted to cubic Bezier curves.
- Basic shapes: rect (with optional rx / ry rounded corners), circle, ellipse, line, polyline, polygon.
- Transforms: matrix, translate, scale, rotate (with optional center), skewX, skewY; composition left-to-right.
- viewBox + preserveAspectRatio (all 9 alignments x meet | slice; `none` stretches).
- Groups (`<g>`), `<defs>` + `<use>` references with cycle detection.
- Paint state: solid fill / stroke (147 named CSS colors, hex, `rgb()`, `rgba()`, `currentColor`), stroke-width, stroke-linecap, stroke-linejoin, stroke-miterlimit, stroke-dasharray + stroke-dashoffset, fill-rule (nonzero | evenodd), fill-opacity, stroke-opacity, opacity (multiplicative).
- Linear and radial gradients (`<linearGradient>` / `<radialGradient>`): objectBoundingBox and userSpaceOnUse units, gradientTransform, href stop inheritance, multi-stop, on fill and stroke, uniform AND per-stop stop-opacity, and `spreadMethod` pad / reflect / repeat.
- `<pattern>` tiling fills and strokes (`/PatternType 1`): patternUnits objectBoundingBox / userSpaceOnUse, patternTransform, nested `viewBox`, href inheritance; pattern children may be shapes, groups, and `<use>` (text and image inside a pattern are stripped).
- `<clipPath>` (`clip-path="url(#id)"`): native PDF clipping (`W` / `W*` `n`), clipPathUnits userSpaceOnUse / objectBoundingBox, any element, shapes + `<use>`, clip-rule nonzero / evenodd, union of children. Clip transforms are baked into the coordinates (never emitted as `cm`).
- `<mask>` luminance soft masks: PDF `/SMask` + Form XObject `/Group /S /Transparency`, maskUnits + maskContentUnits (objectBoundingBox + userSpaceOnUse), any maskable element. The mask Form's `/Matrix` is identity with the `/BBox` projected into user space (a PDF Form `/Matrix` is concatenated with the CTM active at the `/gs`, the opposite convention from a tiling pattern).
- `<symbol>` + `<marker>`: `<use>` of a `<symbol viewBox + preserveAspectRatio>` resolves to a group with the viewBox-to-use-box matrix; `marker-start` / `marker-mid` / `marker-end` on line / polyline / polygon / path with markerUnits stroke / userSpace, orient num / auto / auto-start-reverse, refX / refY, emitted inline (`q` / `cm` / `Q`), never as an indirect object.
- `<text>`, `<tspan>`, `<textPath>` as real selectable PDF text (`BT` / `Tf` / `Tm` / `Tj` / `ET`): text-anchor, font-size / weight / style, dx / dy, fill + stroke, opacity, transform, inheritance through `<g>`. Standard 14 fonts and any registered custom TTF / OTF family (matched by `font-family`, with automatic glyph subsetting via the shared `GlyphUsage`). `<textPath>` lays glyphs along a referenced `<path>`, rotated to the tangent, with startOffset (absolute / percentage) and text-anchor. The Y flip is applied via the text matrix `Tm [1 0 0 -1 bx by]`.
- `<image>`: PNG / JPEG data URIs (see subsection below).
- `<filter>` (`filter="url(#id)"`): hybrid pure-PHP raster (see subsection below).
- Presentation attributes, inline `style="..."`, AND `<style>` CSS (simple + compound selectors: type / `.class` / `#id` / `*` / `rect.foo`, comma groups; cascade by specificity then source order). Precedence: inline style > CSS rule > direct attribute > inherited. CSS is resolved entirely at parse time.

### SVG embedded images

An SVG `<image>` element whose `href` attribute is a PNG or JPEG data URI (`data:image/png;base64,...` or `data:image/jpeg;base64,...`) is handled as follows:

- The base64 payload is decoded at parse time and handed to `Image::fromBytes`, producing a raster XObject (Image + optional SMask for PNG alpha, same pipeline as standalone images).
- Each distinct data URI is deduped by content hash within the SVG Form XObject. The resulting child XObject is registered in the form's `/Resources/XObject` dictionary as `/Im0`, `/Im1`, etc. (0-based), and drawn with the PDF `Do` operator.
- `objectCount` is recursive: form object + sum of each distinct child image's object count. A PNG with alpha contributes 2 objects (image + SMask); a JPEG contributes 1.
- The placement matrix is `[fw 0 0 -fh fx fy+fh]`, where `fw`/`fh` are the rendered width/height in points and `fx`/`fy` are the top-left corner. The `-fh` flip accounts for PDF's top-row-first raster orientation within the renderer's y-down coordinate space.
- `preserveAspectRatio` with `slice` installs a rectangular clip path around the viewport before the `Do` call, matching the clip behavior used for the top-level SVG.
- `transform` and `opacity` on the `<image>` element go through the same matrix-concat and ExtGState mechanisms used for other SVG elements.
- External `href` values (local file paths or http(s) URLs) are silently ignored - the renderer has no network or filesystem access at emit time. Other data URI formats (GIF, WebP, `svg+xml`) are also ignored, as are images with non-positive computed width or height.

### SVG filters

An element carrying `filter="url(#id)"` is rendered with a **hybrid raster** strategy (the approach Batik / Illustrator take; no other pure-PHP PDF library implements SVG filters):

- The filter region is rasterized in **pure deterministic PHP** - no GD drawing, so byte-identity golden tests stay stable across platforms. The result is embedded as an image XObject (`/DeviceRGB` samples) plus a `/DeviceGray` `/SMask`, both `/FlateDecode`, and placed back with a region-local `cm`.
- **Text inside a filtered subtree is NOT rasterized**: it is redrawn sharp (vector) on top of the raster via `renderTextOnly`, so it stays selectable and crisp.
- The pipeline is a named-buffer graph (`SourceGraphic` / `SourceAlpha` / per-primitive `result`), with null-`in` chaining (first primitive defaults to `SourceGraphic`, later ones to the previous result). Blur / composite math runs on premultiplied alpha; color math runs in linearRGB via `ColorSpace` LUTs.
- First-wave primitives: `feGaussianBlur` (3-pass box blur), `feOffset`, `feFlood`, `feMerge`, `feBlend` (normal / multiply / screen / darken / lighten, on premultiplied color), `feComposite` (Porter-Duff over / in / out / atop / xor + arithmetic), `feColorMatrix` (matrix / saturate / hueRotate / luminanceToAlpha), and `feDropShadow` (expanded to blur + offset + flood + merge).
- Resolution is `Document::setSvgFilterResolution(int $dpi)` (default 300, capped at 2000 px per side). `pxPerUnit = dpi / 72` in viewBox-local coordinates - it must NOT include the viewBox-to-unit prologue scale, or the raster collapses.
- `ext-gd` is **optional** (composer `suggest`, not `require`): used only to decode a JPEG referenced inside a filter. PNG decodes through the built-in `PngMetadata` / `PngFilters` path to raw samples.
- `ImageEmbedder::svgHasFilterPaint` pre-reserves `count * 2` objects in `objectCount` (image + SMask per filtered element) so the xref stays correct.

Known limits: `primitiveUnits="objectBoundingBox"` is parsed but subregion coords are treated as userSpaceOnUse; `color-interpolation-filters="sRGB"` is not wired (always linearRGB); clip / mask / pattern / filter nested inside a filter are ignored; stroke inside a filter is a v1 approximation (perpendicular quads, square joins); the heavier primitives below are deferred.

### Not supported (skipped silently per SVG fallback spec)

- Heavy filter primitives: `feTurbulence`, `feDisplacementMap`, `feConvolveMatrix`, `feMorphology`, `feImage`, `feTile`, and the lighting primitives (`feDiffuseLighting` / `feSpecularLighting`). `BackgroundImage` / `FillPaint` / `StrokePaint` filter inputs are transparent.
- Mesh gradients. `fill="url(#x)"` referencing an unsupported or unresolvable paint server falls back to black.
- Scripts, animations (SMIL), foreignObject.
- `<image>` is supported only for PNG / JPEG data URIs (see subsection above). External href values (local paths or http(s) URLs) and other data URI formats (GIF, WebP, `svg+xml`) are ignored - the renderer has no network or filesystem access at emit time.

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

### Long-term validation (DSS / LTV)

`Document::enableLtv()` makes the document's signatures long-term validatable by
embedding their validation material in a Document Security Store and covering it
with a document timestamp. It is written as incremental revisions appended after
every signature: first a DSS revision, then (when a `Tsa` is given) a
`/DocTimeStamp` revision whose ByteRange covers the DSS.

- **/DSS** is an indirect dictionary referenced from the catalog, carrying
  `/Certs`, `/CRLs` and `/OCSPs` arrays of raw-DER stream objects (one stream
  per certificate, CRL and OCSP response; empty arrays are omitted). The global
  DSS only - no per-signature `/VRI` sub-dictionary, because its key is the
  SHA-1 of a signature's `/Contents` which is not known until after signing; a
  global DSS is sufficient for validators and is what modern signers emit.
- **CRL or OCSP revocation.** Material is collected through an injectable
  `ValidationDataSource` (the same seam shape as `TsaClient`):
  `HttpCrlValidationDataSource` reads each certificate's CRL distribution point
  and fetches the CRL, `HttpOcspValidationDataSource` reads each certificate's
  AIA responder and fetches the OCSP response (the OCSP request is a single
  SHA-1 `CertID` with no nonce, built by `OcspRequestBuilder` over an injectable
  `OcspClient` seam), and `StaticValidationDataSource` supplies material a caller
  obtained itself (and is how the test suite runs offline).
- **Subfilter independent.** This is Adobe-style LTV: it is the presence of
  validation material in the DSS plus a covering document timestamp that makes
  the file long-term validatable, not the CMS subfilter. It works over both the
  default `adbe.pkcs7.detached` and the strict `ETSI.CAdES.detached` signatures
  (see below).
- **Archival (B-LTA).** `enableLtv($source, $timestamp, $timestampCertificateChains)`
  also collects, through the same `ValidationDataSource`, the chain + revocation
  of the certificate that signs the covering `/DocTimeStamp`, and merges it into
  the DSS. The archive timestamp then protects validation material that includes
  its own TSA certificate's revocation, so the whole construct - signature and
  timestamp - validates offline from the embedded DSS (PAdES-B-LTA). Renewal
  (stacking further archive timestamps years later, each preceded by a DSS update
  for the prior timestamp's TSA) is out of scope.

### Strict ETSI.CAdES signatures

`sign(..., format: SignatureFormat::EtsiCadesDetached)` (and the same on
`addSignature()`) emits `/SubFilter /ETSI.CAdES.detached` with a CMS SignedData
built by hand, because PHP's `openssl_cms_sign` cannot inject signed attributes.
`CadesSigner` assembles the DER with the `Der` toolkit.

- **Signed attributes.** `CmsSignedAttributes` builds the three CAdES attributes:
  `contentType` (id-data), `messageDigest` (SHA-256 of the ByteRange content),
  and `signingCertificateV2` (ESS, RFC 5035) - an `ESSCertIDv2` whose `certHash`
  is `sha256(signerCertDer)` plus an `IssuerSerial`, binding the signature to the
  exact signer certificate. The SHA-256 hashAlgorithm is the ESSCertIDv2 DEFAULT
  and is omitted.
- **Sign vs embed (RFC 5652 5.4).** The attributes are signed under an EXPLICIT
  `SET OF` tag (`0x31`) but embedded in the SignerInfo under the `[0] IMPLICIT`
  tag (`0xA0`), over the same content; the `SET OF` elements are DER-sorted
  ascending bytewise.
- **SignerInfo** is v1 with `issuerAndSerialNumber`, digestAlgorithm SHA-256,
  signatureAlgorithm rsaEncryption; the signature is RSA-SHA256 over the `SET OF`
  form (`openssl_sign`). RSA keys only.
- **Composition.** The existing `SignatureTimestamper` adds the RFC 3161 token as
  an unsigned attribute on the hand-built CMS unchanged, so CAdES + a `Tsa` gives
  PAdES-B-T; `enableLtv()` on top is the strict path toward B-LT.

## PDF/A archival conformance

`Document::enablePdfA(PdfALevel $level)` makes the output comply with ISO 19005-2 (PDF/A-2). Two conformance levels are available:

- `PdfALevel::A2B` - PDF/A-2b (basic): correct visual reproduction.
- `PdfALevel::A2U` - PDF/A-2u (unicode): A2b plus ToUnicode maps on every font (already satisfied by custom embedded fonts; standard fonts are prohibited anyway).

`PdfALevel::A3B` / `A3U` are reserved in the enum for a future file-attachment phase but are not yet implemented (attempting to use them throws).

### Namespace: `src/PdfA/`

- **`PdfALevel`** - backed enum carrying the ISO part (2 or 3) and the conformance character (B or U). Helper methods `part()` and `conformance()` are used by the emitters.
- **`OutputIntent`** - builds the `/OutputIntents` array entry: an `/OutputIntent` dictionary with `/S /GTS_PDFA1`, `/OutputConditionIdentifier (sRGB)`, `/DestOutputProfile` pointing at an `IccProfileStream`, plus `/Info` and `/RegistryName`.
- **`IccProfileStream`** - wraps the bundled `resources/icc/sRGB.icc` (a 588-byte littleCMS sRGB profile) in a FlateDecode stream object with `/N 3` (three color components). The same profile object is used for every PDF/A document produced in a session.
- **`PdfAConformanceGuard`** - called by `output()` before serialization; throws `PdfException` for any of the four prohibited combinations:
  - a non-embedded standard font (Helvetica, Times, Courier, etc.) is in use - every font must be embedded via `registerFontFamily()`;
  - encryption is configured;
  - document JavaScript (`addDocumentScript`) is present;
  - appended revisions (`addSignature` / `addDocumentTimestamp` / `enableLtv`) are present.

### How `enablePdfA()` wires into serialization

Calling `enablePdfA()` does three things that all take effect at `output()` time:

1. **Forces the metadata output path** - the XMP packet, the `/Info` dictionary, and the document `/ID` pair are always written (normally `/ID` and full XMP are optional).
2. **Injects `/OutputIntents`** - an `[OutputIntent]` array is added to the catalog referencing the sRGB ICC profile stream.
3. **Passes the level to `XmpWriter`** - the XMP serializer prepends a `pdfaid` RDF description block (`xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/"`) carrying `<pdfaid:part>` (2) and `<pdfaid:conformance>` (B or U). The rest of the XMP packet (dc:, xmp:, pdf: namespaces) is emitted unchanged.

The PDF header is already `%PDF-1.7`, which satisfies the PDF/A-2 version requirement.

### Why the `u` variant is essentially free

PDF/A-2u requires a valid ToUnicode CMap on every font. Custom embedded fonts (the only fonts allowed in a PDF/A document) already carry a `/ToUnicode` stream built by `FontEngine` during subsetting - the same stream that makes copy-paste work correctly. No extra work is needed to satisfy the Unicode conformance level.

### Validation oracle

The e2e golden test `tests/Golden/PdfA2ConformanceTest.php` renders a small document with a custom font, calls `enablePdfA()`, and pipes the output to `veraPDF --flavour 2b` (or `2u`). It asserts `isCompliant="true"` in the veraPDF XML report. The test auto-skips when `veraPDF` or `java` are absent from PATH.

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
