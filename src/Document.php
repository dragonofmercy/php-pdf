<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use Closure;
use DateTimeImmutable;
use DragonOfMercy\PhpPdf\Document\Encryption;
use DragonOfMercy\PhpPdf\Document\Metadata;
use DragonOfMercy\PhpPdf\Document\MetadataStream;
use DragonOfMercy\PhpPdf\Document\PageObjectsBuilder;
use DragonOfMercy\PhpPdf\Document\SubsettedFontObjectsEmitter;
use DragonOfMercy\PhpPdf\Document\XmpWriter;
use DragonOfMercy\PhpPdf\Encryption\Cipher;
use DragonOfMercy\PhpPdf\Encryption\EncryptedPdfWriter;
use DragonOfMercy\PhpPdf\Encryption\EncryptionDictBuilder;
use DragonOfMercy\PhpPdf\Encryption\EncryptionKey;
use DragonOfMercy\PhpPdf\Encryption\ObjectTransformer;
use DragonOfMercy\PhpPdf\Encryption\PasswordHash;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\PdfA\AFRelationship;
use DragonOfMercy\PhpPdf\PdfA\AttachedFile;
use DragonOfMercy\PhpPdf\PdfA\EmbeddedFileEmitter;
use DragonOfMercy\PhpPdf\PdfA\OutputIntent;
use DragonOfMercy\PhpPdf\PdfA\PdfAConformanceGuard;
use DragonOfMercy\PhpPdf\PdfA\PdfALevel;
use DragonOfMercy\PhpPdf\Form\AcroFormEmitter;
use DragonOfMercy\PhpPdf\Form\FormField;
use DragonOfMercy\PhpPdf\Font\Custom\CustomFontKey;
use DragonOfMercy\PhpPdf\Font\Custom\FontResolver;
use DragonOfMercy\PhpPdf\Font\Custom\GlyphUsage;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\Custom\TtfParser;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Image\ImageEmbedder;
use DragonOfMercy\PhpPdf\Image\ImageRegistry;
use DragonOfMercy\PhpPdf\Image\SvgMetadata;
use DragonOfMercy\PhpPdf\Outline\LinkAnnotationEmitter;
use DragonOfMercy\PhpPdf\Outline\OutlineEmitter;
use DragonOfMercy\PhpPdf\Outline\OutlineNode;
use DragonOfMercy\PhpPdf\Page\ColumnLayout;
use DragonOfMercy\PhpPdf\Signature\AppendedDocumentTimestamp;
use DragonOfMercy\PhpPdf\Signature\AppendedFieldRevisionBuilder;
use DragonOfMercy\PhpPdf\Signature\AppendedRevision;
use DragonOfMercy\PhpPdf\Signature\AppendedSignature;
use DragonOfMercy\PhpPdf\Signature\ContentRangePatcher;
use DragonOfMercy\PhpPdf\Signature\DocumentTimestamp;
use DragonOfMercy\PhpPdf\Signature\Ltv\CertificateChain;
use DragonOfMercy\PhpPdf\Signature\Ltv\DssRevision;
use DragonOfMercy\PhpPdf\Signature\Ltv\DssRevisionBuilder;
use DragonOfMercy\PhpPdf\Signature\Ltv\HttpCrlValidationDataSource;
use DragonOfMercy\PhpPdf\Signature\Ltv\ValidationDataSource;
use DragonOfMercy\PhpPdf\Signature\Ltv\ValidationMaterial;
use DragonOfMercy\PhpPdf\Signature\RevisionContext;
use DragonOfMercy\PhpPdf\Signature\Signature;
use DragonOfMercy\PhpPdf\Signature\SignatureDictionaryEmitter;
use DragonOfMercy\PhpPdf\Signature\SignatureFormat;
use DragonOfMercy\PhpPdf\Signature\SignaturePatcher;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Svg\SvgFontResolver;
use DragonOfMercy\PhpPdf\Tagging\StructTreeEmitter;
use DragonOfMercy\PhpPdf\Tagging\StructureTree;
use DragonOfMercy\PhpPdf\Tagging\TaggingConformanceGuard;
use DragonOfMercy\PhpPdf\Writer\IncrementalWriter;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfBoolean;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use DragonOfMercy\PhpPdf\Writer\Object\TextString;
use DragonOfMercy\PhpPdf\Writer\PdfDate;
use DragonOfMercy\PhpPdf\Writer\PdfObjectAllocator;
use DragonOfMercy\PhpPdf\Writer\PdfWriter;
use DragonOfMercy\PhpPdf\Writer\Trailer;
use DragonOfMercy\PhpPdf\Writer\XrefTable;

final class Document
{
    private const string VERSION = '0.1-phase1a';

    private const string HEADER = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";

    /** Default margin (in the document's unit) applied by setAutoPageBreak(true) when current margins are zero. */
    private const float DEFAULT_AUTO_BREAK_MARGIN = 20.0;

    /** Initial default border line width (in the document's unit) applied to Border factories without an explicit withWidth() call. */
    private const float INITIAL_DEFAULT_BORDER_WIDTH = 0.25;

    /** @var list<Page> */
    private array $pages = [];

    private readonly FontRegistry $fontRegistry;
    private readonly MetricsRegistry $metricsRegistry;
    private readonly ImageRegistry $imageRegistry;

    private ?Metadata $metadata = null;
    private ?Encryption $encryption = null;
    private ?Signature $signature = null;

    /** @var list<AppendedRevision|DssRevision> appended incremental revisions, in call order */
    private array $appendedRevisions = [];

    /** @var list<SigningCertificate> */
    private array $signingCertificates = [];

    private bool $ltvEnabled = false;

    private ?PdfALevel $pdfALevel = null;

    private bool $taggingEnabled = false;
    private bool $uaConformance = false;
    private ?string $language = null;
    private ?StructureTree $structureTree = null;
    private int $linkStructParentCounter = 0;

    /**
     * Stable /ID for the metadata-less document-timestamp path, generated once
     * so repeated output() calls (and both stacked revisions) share one value.
     */
    private ?string $generatedDocumentId = null;

    private ?PageLayout $pageLayout = null;
    private ?PageMode $pageMode = null;
    private ?OpenAction $openAction = null;

    /** Last format used (or implicit default). Reused when addPage() is called with no $format. */
    private PageFormat $lastFormat = PageFormat::A4;

    /** @var array{float, float}|null Custom dimensions [w, h] in user unit; takes precedence over $lastFormat when set. */
    private ?array $lastCustom = null;

    private Orientation $lastOrientation = Orientation::PORTRAIT;

    /** Default font applied to pages created via addPage(). */
    private Font $defaultFont;
    private float $defaultSize = 11.0;

    /** @var array<string, array{regular: ParsedTtf, bold: ?ParsedTtf, italic: ?ParsedTtf, boldItalic: ?ParsedTtf}> */
    private array $customFontFamilies = [];

    private ?FontResolver $fontResolver = null;

    /** Raster resolution (DPI) used when rasterizing SVG filter subtrees. */
    private int $svgFilterDpi = 300;

    private readonly GlyphUsage $glyphUsage;

    /** Default per-side cells padding (document unit) for new pages, null = page builtin. */
    private ?CellPadding $defaultCellsPadding = null;

    private PageMargins $margins;

    private float $defaultBorderWidthPt;

    private ?Closure $header = null;
    private ?Closure $footer = null;
    private bool $autoPageBreak = false;
    private ?Page $currentPage = null;
    private int $pageCounter = 0;
    private bool $footersRendered = false;

    private ?OutlineNode $outlineRoot = null;

    /** @var array<string, string> name => JavaScript, run on document open */
    private array $documentScripts = [];

    /** @var list<AttachedFile> */
    private array $attachments = [];

    private ?ColumnLayout $columnLayout = null;
    private int $columnIndex = 0;
    private float $columnPageBottomPt = 0.0;

    public function __construct(public readonly Unit $unit = Unit::MM)
    {
        $this->glyphUsage = new GlyphUsage();
        $this->fontRegistry = new FontRegistry();
        $this->metricsRegistry = new MetricsRegistry();
        $this->imageRegistry = new ImageRegistry();
        // PHP forbids method calls in property defaults; resolve here.
        $this->defaultFont = Font::helvetica();
        $this->margins = PageMargins::all(0.0);
        $this->defaultBorderWidthPt = $this->unit->toPoints(self::INITIAL_DEFAULT_BORDER_WIDTH);
    }

    /**
     * Sets the font that newly created pages start with. Existing pages are
     * unaffected. Defaults to Helvetica 11pt.
     */
    public function setDefaultFont(Font $font, float $size): self
    {
        if ($size <= 0) {
            throw new PdfException('Font size must be positive, got ' . $size);
        }
        $this->defaultFont = $font;
        $this->defaultSize = $size;
        return $this;
    }

    /**
     * Registers a custom TTF font family by alias. The regular variant is
     * required; bold/italic/boldItalic are optional and fall back to regular
     * (or to the closest match) when missing.
     *
     * Files are read and parsed eagerly: missing files, invalid TTFs, OTF/CFF,
     * and missing required tables raise PdfException at this call.
     */
    public function registerFontFamily(
        string $alias,
        string $regular,
        ?string $bold = null,
        ?string $italic = null,
        ?string $boldItalic = null,
    ): self {
        if (isset($this->customFontFamilies[$alias])) {
            throw new PdfException("Font family '{$alias}' is already registered; each alias can be registered only once");
        }
        $regularParsed = $this->parseFontFile($alias, 'regular', $regular);
        $boldParsed = $bold !== null ? $this->parseFontFile($alias, 'bold', $bold) : null;
        $italicParsed = $italic !== null ? $this->parseFontFile($alias, 'italic', $italic) : null;
        $boldItalicParsed = $boldItalic !== null ? $this->parseFontFile($alias, 'boldItalic', $boldItalic) : null;

        $this->customFontFamilies[$alias] = [
            'regular' => $regularParsed,
            'bold' => $boldParsed,
            'italic' => $italicParsed,
            'boldItalic' => $boldItalicParsed,
        ];
        $this->fontResolver = new FontResolver(
            $this->customFontFamilies,
            $this->metricsRegistry,
            $this->glyphUsage,
        );
        return $this;
    }

    /**
     * @internal Used by Page to resolve custom Font instances to ParsedTtf.
     */
    public function fontResolver(): ?FontResolver
    {
        return $this->fontResolver;
    }

    private function parseFontFile(string $alias, string $variant, string $path): ParsedTtf
    {
        if (!is_file($path)) {
            throw new PdfException("Font file not found for alias '{$alias}' ({$variant}): {$path}");
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new PdfException("Cannot read font file for alias '{$alias}' ({$variant}): {$path}");
        }
        return TtfParser::parse($bytes, "{$alias} ({$variant})");
    }

    /**
     * Sets the cells padding that newly created pages start with. Existing
     * pages are unaffected. A bare float means "same value all four sides";
     * a {@see CellPadding} instance allows per-side control.
     */
    public function setDefaultCellsPadding(float|CellPadding $padding): self
    {
        $this->defaultCellsPadding = $padding instanceof CellPadding
            ? $padding
            : CellPadding::all((float) $padding);
        return $this;
    }

    /**
     * Sets the document-wide margins. A bare float means "same value all four sides";
     * a {@see PageMargins} instance allows per-side control. Used as the reserved
     * zone for header (top) / footer (bottom) callbacks, and as the default anchor
     * for cell() rows.
     */
    public function setMargins(float|PageMargins $margins): self
    {
        $this->margins = $margins instanceof PageMargins
            ? $margins
            : PageMargins::all($margins);
        return $this;
    }

    public function margins(): PageMargins
    {
        return $this->margins;
    }

    /**
     * Sets the document-wide default border line width. Used by {@see Border}
     * instances whose width has not been set explicitly via
     * {@see Border::withWidth()}. Per-page override available via
     * {@see Page::setDefaultBorderWidth()}. Initial value is 0.25 in the
     * document unit.
     */
    public function setDefaultBorderWidth(float $width): self
    {
        if ($width <= 0) {
            throw new PdfException('Default border width must be positive, got ' . $width);
        }
        $this->defaultBorderWidthPt = $this->unit->toPoints($width);
        return $this;
    }

    public function defaultBorderWidth(): float
    {
        return $this->unit->fromPoints($this->defaultBorderWidthPt);
    }

    /**
     * Sets the raster resolution (DPI) used when SVG filter subtrees are
     * rasterized into image XObjects. Higher values yield sharper filter
     * output at the cost of larger PDFs. Initial value is 300.
     */
    public function setSvgFilterResolution(int $dpi): self
    {
        if ($dpi < 1) {
            throw new PdfException("SVG filter resolution must be positive, got {$dpi}");
        }
        $this->svgFilterDpi = $dpi;
        return $this;
    }

    /**
     * @internal Used by {@see Page::resolveDefaultBorderWidthPt()}.
     */
    public function defaultBorderWidthPt(): float
    {
        return $this->defaultBorderWidthPt;
    }

    public function setHeader(?Closure $header): self
    {
        $this->header = $header;
        return $this;
    }

    public function setFooter(?Closure $footer): self
    {
        $this->footer = $footer;
        return $this;
    }

    public function setAutoPageBreak(bool $auto): self
    {
        $this->autoPageBreak = $auto;
        if ($auto && $this->margins->isZero()) {
            $this->margins = PageMargins::all(self::DEFAULT_AUTO_BREAK_MARGIN);
        }
        return $this;
    }

    public function autoPageBreak(): bool
    {
        return $this->autoPageBreak;
    }

    public function columnLayout(): ?ColumnLayout
    {
        return $this->columnLayout;
    }

    public function columnIndex(): int
    {
        return $this->columnIndex;
    }

    /** @internal Begin column flow with the given layout (set by Page::columns()). */
    public function beginColumns(ColumnLayout $layout): void
    {
        $this->columnLayout = $layout;
        $this->columnIndex = 0;
        $this->columnPageBottomPt = $layout->topPt;
    }

    /** @internal End column flow. */
    public function endColumns(): void
    {
        $this->columnLayout = null;
        $this->columnIndex = 0;
        $this->columnPageBottomPt = 0.0;
    }

    /** @internal */
    public function setColumnIndex(int $index): void
    {
        $this->columnIndex = $index;
    }

    /** @internal Record the lowest content bottom reached on the current page. */
    public function recordColumnBottomPt(float $bottomPt): void
    {
        if ($bottomPt > $this->columnPageBottomPt) {
            $this->columnPageBottomPt = $bottomPt;
        }
    }

    /** @internal The lowest content bottom on the current page during column flow. */
    public function columnPageBottomPt(): float
    {
        return $this->columnPageBottomPt;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function getCurrentPage(): Page
    {
        if ($this->currentPage === null) {
            throw new PdfException('No current page: call addPage() first');
        }
        return $this->currentPage;
    }

    public function metadata(): Metadata
    {
        return $this->metadata ??= new Metadata();
    }

    public function encryption(): Encryption
    {
        return $this->encryption ??= new Encryption();
    }

    public function sign(
        SigningCertificate $certificate,
        string $field,
        ?string $reason = null,
        ?string $location = null,
        ?string $contactInfo = null,
        ?\DateTimeImmutable $signedAt = null,
        int $maxSignatureBytes = 16384,
        ?Tsa $timestamp = null,
        SignatureFormat $format = SignatureFormat::Pkcs7Detached,
    ): self {
        $this->signature = new Signature(
            $certificate,
            $field,
            $reason,
            $location,
            $contactInfo,
            $signedAt ?? new \DateTimeImmutable(),
            $maxSignatureBytes,
            $timestamp,
            $format,
        );
        $this->signingCertificates[] = $certificate;
        return $this;
    }

    public function addDocumentTimestamp(Tsa $tsa, int $maxSignatureBytes = 16384): self
    {
        $this->appendDocumentTimestamp($tsa, $maxSignatureBytes);
        return $this;
    }

    private function appendDocumentTimestamp(Tsa $tsa, int $maxSignatureBytes): void
    {
        $name = 'DocTimeStamp' . (count($this->appendedRevisions) + 1);
        $this->appendedRevisions[] = new AppendedDocumentTimestamp(
            new DocumentTimestamp($tsa, $maxSignatureBytes),
            $name,
        );
    }

    public function addSignature(
        SigningCertificate $certificate,
        ?string $reason = null,
        ?string $location = null,
        ?string $contactInfo = null,
        ?\DateTimeImmutable $signedAt = null,
        int $maxSignatureBytes = 16384,
        ?Tsa $timestamp = null,
        SignatureFormat $format = SignatureFormat::Pkcs7Detached,
    ): self {
        $name = 'Signature' . (count($this->appendedRevisions) + 1);
        $signature = new Signature(
            $certificate,
            $name,
            $reason,
            $location,
            $contactInfo,
            $signedAt ?? new \DateTimeImmutable(),
            $maxSignatureBytes,
            $timestamp,
            $format,
        );
        $this->appendedRevisions[] = new AppendedSignature($signature);
        $this->signingCertificates[] = $certificate;
        return $this;
    }

    /**
     * Makes the document's signatures long-term validatable: collects the signer
     * certificate chains plus their CRLs into a /DSS, and (when a timestamp is
     * given) covers them with a document timestamp. Must be called after sign()
     * and any addSignature(); the DSS is appended as the last incremental
     * revisions so it covers every signature.
     *
     * @param list<list<string>> $timestampCertificateChains PEM chains (TSA signer
     *        cert first, then issuers) whose revocation is collected into the DSS
     *        so a covering document timestamp is itself long-term validatable (B-LTA).
     */
    public function enableLtv(
        ?ValidationDataSource $source = null,
        ?Tsa $timestamp = null,
        array $timestampCertificateChains = [],
    ): self {
        if ($this->signingCertificates === []) {
            throw new PdfException('enableLtv requires at least one signature (call sign() or addSignature() first)');
        }
        if ($this->ltvEnabled) {
            throw new PdfException('enableLtv can only be called once per document');
        }
        $this->ltvEnabled = true;
        $resolver = $source ?? new HttpCrlValidationDataSource();

        $material = ValidationMaterial::of([], []);
        // Validation material is gathered eagerly (here, not at output()) so a
        // network failure surfaces at the enableLtv() call rather than mid-output().
        foreach ($this->signingCertificates as $credential) {
            $material = $material->merge($resolver->collect(CertificateChain::chainPem($credential)));
        }
        foreach ($timestampCertificateChains as $tsaChainPem) {
            $material = $material->merge($resolver->collect($tsaChainPem));
        }
        if ($material->certificates === []) {
            throw new PdfException('enableLtv: the validation data source returned no certificates');
        }
        if ($material->crls === [] && $material->ocsps === []) {
            throw new PdfException('enableLtv: the validation data source returned no CRLs or OCSP responses');
        }
        $this->appendedRevisions[] = new DssRevision($material);

        if ($timestamp !== null) {
            $this->appendDocumentTimestamp($timestamp, 16384);
        }
        return $this;
    }

    /**
     * Makes output() emit a PDF/A conformant file at the given level (ISO 19005-2
     * part 2, conformance B or U). Forces the metadata output path so the XMP
     * packet, Info dictionary, and document /ID are always present, embeds an
     * sRGB output intent, and stamps the pdfaid schema. Throws at output() if the
     * document uses a non-embedded standard font, encryption, document scripts,
     * or appended revisions.
     */
    public function enablePdfA(PdfALevel $level): self
    {
        $this->pdfALevel = $level;
        return $this;
    }

    /**
     * Turns on tagged-PDF output: the high-level API (cell, image, table,
     * markdown) accumulates a logical structure tree that output() serializes
     * into a StructTreeRoot. The optional language tag (e.g. 'en-US') is written
     * to the catalog /Lang. Off by default; when off, output is byte-identical
     * to an untagged document.
     */
    public function enableTagging(?string $lang = null): self
    {
        if ($lang !== null && preg_match('/^[A-Za-z]{1,8}(-[A-Za-z0-9]{1,8})*$/', $lang) !== 1) {
            throw new PdfException("Invalid language tag for enableTagging, got '{$lang}'");
        }
        $this->taggingEnabled = true;
        $this->language = $lang;
        $this->structureTree ??= new StructureTree();
        return $this;
    }

    public function isTaggingEnabled(): bool
    {
        return $this->taggingEnabled;
    }

    /**
     * Opts the document into PDF/UA-1 (ISO 14289-1) accessible output: implies
     * enableTagging($lang) for the logical structure tree, then forces a
     * DisplayDocTitle viewer preference, an XMP /Metadata stream, and a
     * fail-fast conformance guard at output() (every font must be embedded, a
     * document title must be set, every figure must carry alternate text,
     * headings must not skip levels, and link annotations are rejected until
     * Phase 2b). Off by default; when off, output is byte-identical to an
     * untagged document. Idempotent.
     */
    public function enablePdfUA(?string $lang = null): self
    {
        $this->enableTagging($lang);
        $this->uaConformance = true;

        return $this;
    }

    public function isPdfUA(): bool
    {
        return $this->uaConformance;
    }

    public function language(): ?string
    {
        return $this->language;
    }

    /**
     * @internal The build-time structure-tree accumulator, or null when tagging
     *           is disabled. Drawing code opens/closes elements on it.
     */
    public function structureTree(): ?StructureTree
    {
        return $this->taggingEnabled ? $this->structureTree : null;
    }

    /**
     * @internal Returns the next 0-based ordinal for a tagged link annotation's
     *           /StructParent key. Each call yields 0, 1, 2, ... in draw order.
     */
    public function nextLinkStructParentIndex(): int
    {
        return $this->linkStructParentCounter++;
    }

    /**
     * Embeds a file in the document. With enablePdfA(PdfALevel::A3B|A3U) this is a
     * conformant PDF/A-3 associated file (e.g. a Factur-X invoice XML); without
     * PDF/A it is a plain attachment. Rejected at PDF/A-2 (part 2 forbids embedded
     * files). The mod date defaults to now; pass it explicitly for deterministic output.
     */
    public function attachFile(
        string $bytes,
        string $name,
        AFRelationship $relationship = AFRelationship::Data,
        string $mime = 'application/octet-stream',
        ?string $description = null,
        ?\DateTimeImmutable $modDate = null,
    ): self {
        $this->attachments[] = new AttachedFile(
            $name,
            $bytes,
            $relationship,
            $mime,
            $description,
            $modDate ?? new \DateTimeImmutable(),
        );
        return $this;
    }

    /**
     * Returns the outline (bookmarks) tree root. The first call creates the
     * root lazily; subsequent calls return the same instance so the user can
     * keep adding nodes. The tree is only emitted if it has at least one
     * child - a bare outline() call without add() has no effect on the
     * output.
     */
    public function outline(): OutlineNode
    {
        return $this->outlineRoot ??= OutlineNode::root();
    }

    /**
     * Registers a document-level JavaScript action under the given name. The
     * script is added to the /Names /JavaScript name tree and executed by the
     * viewer when the document opens (Adobe Acrobat compatible).
     *
     * Names must be unique and non-empty; the JS body must be non-empty.
     * Multiple scripts are emitted in lexicographic key order per PDF 32000-1 7.9.6.
     */
    public function addDocumentScript(string $name, string $js): self
    {
        if ($name === '') {
            throw new PdfException('Document script name cannot be empty');
        }
        if (isset($this->documentScripts[$name])) {
            throw new PdfException(sprintf("Document script name '%s' is already registered", $name));
        }
        if ($js === '') {
            throw new PdfException('Document script JavaScript cannot be empty');
        }
        $this->documentScripts[$name] = $js;
        return $this;
    }

    /**
     * How the viewer should arrange pages (single, columns, two-page spread).
     * See {@see PageLayout}. Pass `null` to clear and let the viewer decide.
     */
    public function setPageLayout(?PageLayout $layout): self
    {
        $this->pageLayout = $layout;
        return $this;
    }

    /**
     * Which side panel (outline, thumbs, attachments...) the viewer reveals
     * on opening, or whether to launch full screen. See {@see PageMode}.
     */
    public function setPageMode(?PageMode $mode): self
    {
        $this->pageMode = $mode;
        return $this;
    }

    /**
     * Initial view (target page + zoom/fit) applied when the document opens.
     * See {@see OpenAction} for the named constructors.
     */
    public function setOpenAction(?OpenAction $action): self
    {
        $this->openAction = $action;
        return $this;
    }

    /**
     * Appends a new page. Without arguments, reuses the format and orientation
     * from the previous addPage() call (or A4 portrait on the first call).
     *
     * Side-effects: assigns the next sequential pageNumber, becomes the
     * getCurrentPage(), fires the header callback (if any), then positions the
     * cursor at (leftMargin, topMargin).
     *
     * @param PageFormat|array{0: int|float, 1: int|float}|null $format A standard
     *     format, a [width, height] pair in the document's unit for custom sizes
     *     (labels, business cards), or null to keep the previous value.
     */
    public function addPage(
        PageFormat|array|null $format = null,
        ?Orientation $orientation = null,
    ): Page {
        if ($format !== null) {
            if (is_array($format)) {
                $this->lastCustom = $this->validateCustom($format);
            } else {
                $this->lastFormat = $format;
                $this->lastCustom = null;
            }
        }
        if ($orientation !== null) {
            $this->lastOrientation = $orientation;
        }

        if ($this->lastCustom !== null) {
            // Custom dimensions are taken verbatim; orientation does not apply.
            [$w, $h] = $this->lastCustom;
            $widthPoints = $this->unit->toPoints($w);
            $heightPoints = $this->unit->toPoints($h);
        } else {
            [$mmW, $mmH] = $this->lastFormat->dimensionsMm();
            if ($this->lastOrientation === Orientation::LANDSCAPE) {
                [$mmW, $mmH] = [$mmH, $mmW];
            }
            $widthPoints = Unit::MM->toPoints($mmW);
            $heightPoints = Unit::MM->toPoints($mmH);
        }

        $page = new Page(
            pageWidth: $widthPoints,
            pageHeight: $heightPoints,
            fontRegistry: $this->fontRegistry,
            metricsRegistry: $this->metricsRegistry,
            imageRegistry: $this->imageRegistry,
            unit: $this->unit,
            defaultFont: $this->defaultFont,
            defaultSize: $this->defaultSize,
            defaultCellsPadding: $this->defaultCellsPadding,
            fontResolver: $this->fontResolver,
            margins: $this->margins,
            document: $this,
        );
        $page->setPageNumber(++$this->pageCounter);
        $this->currentPage = $page;
        if ($this->header !== null) {
            // Restore the font state after the header runs so the body picks up
            // the page defaults (or whatever the caller set previously), not the
            // font the header callback left behind. Without this, an auto-break
            // produces a new page whose first cell inherits the header's font.
            $savedFontState = $page->captureFontState();
            $page->inHeaderRender = true;
            $header = $this->header;
            try {
                $page->withArtifactScope(function () use ($header, $page): void {
                    $header($page);
                });
            } finally {
                $page->inHeaderRender = false;
                $page->restoreFontState($savedFontState);
            }
        }
        // Position cursor at the top-left of the content area (inside margins).
        $page->setXY($this->margins->left, $this->margins->top);
        if ($this->columnLayout !== null) {
            // Column flow continues on the new page starting at column 0.
            $this->columnIndex = 0;
            $this->columnPageBottomPt = $this->columnLayout->topPt;
            $page->setXY(
                $this->unit->fromPoints($this->columnLayout->leftPtForColumn(0)),
                $this->unit->fromPoints($this->columnLayout->topPt),
            );
        }
        $this->pages[] = $page;
        $page->setPageIndex(count($this->pages) - 1);
        return $page;
    }

    /**
     * @param array<int|string, mixed> $format
     * @return array{float, float}
     */
    private function validateCustom(array $format): array
    {
        if (count($format) !== 2 || !array_is_list($format)) {
            throw new PdfException('Custom page format must be [width, height]');
        }
        [$w, $h] = $format;
        if ((!is_int($w) && !is_float($w)) || (!is_int($h) && !is_float($h))) {
            throw new PdfException('Custom page format dimensions must be numeric');
        }
        if ($w <= 0) {
            throw new PdfException('Page width must be positive, got ' . $w);
        }
        if ($h <= 0) {
            throw new PdfException('Page height must be positive, got ' . $h);
        }
        return [(float) $w, (float) $h];
    }

    public function output(): string
    {
        if ($this->pages === []) {
            throw new PdfException('Document has no pages');
        }

        if ($this->signature !== null && $this->encryption !== null) {
            throw new PdfException('Signing an encrypted document is not supported');
        }

        if ($this->appendedRevisions !== [] && $this->encryption !== null) {
            throw new PdfException('Appended signatures or timestamps are not supported on an encrypted document');
        }

        $this->runFooters();

        if ($this->pdfALevel !== null) {
            (new PdfAConformanceGuard())->verify(
                standardFonts: $this->fontRegistry->registeredFonts(),
                hasEncryption: $this->encryption !== null,
                hasAppendedRevisions: $this->appendedRevisions !== [],
                hasDocumentScripts: $this->hasDocumentScripts(),
                hasAttachmentsAtPart2: !$this->pdfALevel->allowsEmbeddedFiles() && $this->attachments !== [],
            );
        }

        if ($this->isPdfUA()) {
            $tree = $this->structureTree();
            if ($tree === null) {
                throw new PdfException('Internal: PDF/UA mode without a structure tree');
            }
            (new TaggingConformanceGuard())->verify(
                standardFonts: $this->fontRegistry->registeredFonts(),
                title: $this->metadata?->title,
                tree: $tree,
                hasLinkAnnotations: $this->hasLinkAnnotations(),
            );
        }
        // PDF/A, PDF/UA, and attached files all require the metadata output path
        // (XMP + /ID for PDF/A and PDF/UA, the /Names /EmbeddedFiles tree for
        // attachments). PDF/UA-1 (ISO 14289-1, 7.1) mandates an XMP packet.
        if ($this->pdfALevel !== null || $this->attachments !== [] || $this->isPdfUA()) {
            $this->metadata();
        }

        if ($this->encryption !== null) {
            return $this->outputEncrypted($this->encryption, $this->metadata);
        }

        if ($this->appendedRevisions !== []) {
            $baseNames = [];
            foreach ($this->pages as $p) {
                foreach ($p->getFormFields() as $f) {
                    $baseNames[$f->name()] = true;
                }
            }
            foreach ($this->appendedRevisions as $revision) {
                if ($revision instanceof DssRevision) {
                    continue;
                }
                if (isset($baseNames[$revision->fieldName()])) {
                    throw new PdfException(sprintf(
                        "Appended revision field name '%s' collides with an existing form field",
                        $revision->fieldName(),
                    ));
                }
            }
            return $this->outputWithAppendedRevisions();
        }

        $bytes = ($this->metadata === null && !$this->isPdfUA())
            ? $this->outputWithoutMetadata()
            : $this->outputWithMetadata($this->metadata());

        if ($this->signature !== null) {
            $bytes = (new SignaturePatcher())->patch($bytes, $this->signature);
        }

        return $bytes;
    }

    private function runFooters(): void
    {
        // Guard against re-entry: footer callbacks emit into page content
        // streams, so repeated output() calls would otherwise duplicate them.
        if ($this->footer === null || $this->footersRendered) {
            return;
        }
        $this->footersRendered = true;
        $footer = $this->footer;
        $totalPages = count($this->pages);
        $previousCurrent = $this->currentPage;
        try {
            foreach ($this->pages as $i => $page) {
                $this->currentPage = $page;
                $savedFontState = $page->captureFontState();
                try {
                    $page->withArtifactScope(function () use ($footer, $page, $i, $totalPages): void {
                        $footer($page, $i + 1, $totalPages);
                    });
                } finally {
                    $page->restoreFontState($savedFontState);
                }
            }
        } finally {
            $this->currentPage = $previousCurrent;
        }
    }

    public function save(string $path): void
    {
        $bytes = $this->output();
        $result = @file_put_contents($path, $bytes);
        if ($result === false) {
            throw new PdfException("Failed to write PDF to {$path}");
        }
    }

    private function hasDocumentScripts(): bool
    {
        return $this->documentScripts !== [];
    }

    /** True when any page declares a link annotation (rejected under PDF/UA until Phase 2b). */
    private function hasLinkAnnotations(): bool
    {
        foreach ($this->pages as $page) {
            if ($page->getLinkAnnotations() !== []) {
                return true;
            }
        }
        return false;
    }

    private function outputWithoutMetadata(): string
    {
        $pagesRef = PdfReference::to(2, 0);

        [$pageAndContentObjects, $pageRefs, $pageHeightsPt, $allWidgets, $acroFormRef] = $this->buildPagesFontsImages(firstObjectNumber: 3, pagesRef: $pagesRef);
        unset($allWidgets); // consumed inside buildPagesFontsImages

        $catalogDict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Catalog'))
            ->withEntry(Name::of('Pages'), $pagesRef);
        if ($acroFormRef !== null) {
            $catalogDict = $catalogDict->withEntry(Name::of('AcroForm'), $acroFormRef);
        }
        $catalogDict = $this->withViewerPrefs($catalogDict, $pageRefs);

        $nextObjectNumber = 3 + count($pageAndContentObjects);
        [$catalogDict, $outlineObjects] = $this->withOutlines($catalogDict, $pageRefs, $pageHeightsPt, $nextObjectNumber);
        $nextObjectNumber += count($outlineObjects);
        $catalogDict = $this->withNames($catalogDict, []);
        [$catalogDict, $structObjects] = $this->withStructTree($catalogDict, $pageRefs, $nextObjectNumber);

        $catalog = IndirectObject::of(1, 0, $catalogDict);

        $pages = IndirectObject::of(
            2,
            0,
            Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Pages'))
                ->withEntry(Name::of('Kids'), PdfArray::of(...$pageRefs))
                ->withEntry(Name::of('Count'), PdfNumber::ofInt(count($this->pages))),
        );

        return (new PdfWriter())->write(
            [$catalog, $pages, ...$pageAndContentObjects, ...$outlineObjects, ...$structObjects],
            $catalog->reference(),
        );
    }

    private function outputWithMetadata(Metadata $metadata): string
    {
        $effective = clone $metadata;
        $effective->producer ??= 'phppdf ' . self::VERSION;
        $effective->creationDate ??= new DateTimeImmutable();

        $pagesRef = PdfReference::to(2, 0);
        $metadataStreamRef = PdfReference::to(4, 0);

        [$pageAndContentObjects, $pageRefs, $pageHeightsPt, $allWidgets, $acroFormRef] = $this->buildPagesFontsImages(firstObjectNumber: 5, pagesRef: $pagesRef);
        unset($allWidgets); // consumed inside buildPagesFontsImages

        $catalogDict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Catalog'))
            ->withEntry(Name::of('Pages'), $pagesRef)
            ->withEntry(Name::of('Metadata'), $metadataStreamRef);
        if ($acroFormRef !== null) {
            $catalogDict = $catalogDict->withEntry(Name::of('AcroForm'), $acroFormRef);
        }
        $catalogDict = $this->withViewerPrefs($catalogDict, $pageRefs);

        $nextObjectNumber = 5 + count($pageAndContentObjects);
        [$catalogDict, $outlineObjects] = $this->withOutlines($catalogDict, $pageRefs, $pageHeightsPt, $nextObjectNumber);

        $afterOutlines = $nextObjectNumber + count($outlineObjects);

        $attachmentObjects = [];
        $embeddedFilesForNames = [];
        $afRefs = [];
        if ($this->attachments !== []) {
            $emit = (new EmbeddedFileEmitter())->emit($this->attachments, $afterOutlines);
            $attachmentObjects = $emit['objects'];
            $afRefs = $emit['filespecRefs'];
            foreach ($this->attachments as $i => $a) {
                $embeddedFilesForNames[] = ['name' => $a->name, 'ref' => $emit['filespecRefs'][$i]];
            }
            $afterOutlines += count($attachmentObjects);
        }

        $catalogDict = $this->withNames($catalogDict, $embeddedFilesForNames);
        if ($afRefs !== []) {
            $catalogDict = $catalogDict->withEntry(Name::of('AF'), PdfArray::of(...$afRefs));
        }

        $outputIntentObjects = [];
        if ($this->pdfALevel !== null) {
            $profileNumber = $afterOutlines;
            $intentNumber = $afterOutlines + 1;
            [$intent, $profile] = (new OutputIntent())->build(
                intentObjectNumber: $intentNumber,
                profileObjectNumber: $profileNumber,
                iccBytes: self::srgbIccProfile(),
            );
            $outputIntentObjects = [$intent, $profile];
            $catalogDict = $catalogDict->withEntry(
                Name::of('OutputIntents'),
                PdfArray::of(PdfReference::to($intentNumber, 0)),
            );
            $afterOutlines += count($outputIntentObjects);
        }

        [$catalogDict, $structObjects] = $this->withStructTree($catalogDict, $pageRefs, $afterOutlines);

        $catalog = IndirectObject::of(1, 0, $catalogDict);

        $pages = IndirectObject::of(
            2,
            0,
            Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Pages'))
                ->withEntry(Name::of('Kids'), PdfArray::of(...$pageRefs))
                ->withEntry(Name::of('Count'), PdfNumber::ofInt(count($this->pages))),
        );

        $info = IndirectObject::of(3, 0, $this->buildInfoDictionary($effective));

        $xmpXml = (new XmpWriter())->write($effective, $this->pdfALevel, $this->isPdfUA());
        $metadataStream = IndirectObject::of(4, 0, new MetadataStream($xmpXml));

        $objects = [$catalog, $pages, $info, $metadataStream, ...$pageAndContentObjects, ...$outlineObjects, ...$attachmentObjects, ...$outputIntentObjects, ...$structObjects];

        $documentId = $effective->documentId ?? $this->deriveDocumentId($effective);

        return $this->assembleWithTrailer(
            objects: $objects,
            root: $catalog->reference(),
            info: $info->reference(),
            documentId: $documentId,
        );
    }

    /**
     * Builds the finalized revision-1 bytes (with a stable /ID and signature
     * patch applied when signing) plus the RevisionContext the appended
     * incremental revisions need. Used only on the appended-revisions path;
     * the standard output methods are unchanged.
     *
     * @return array{bytes: string, context: RevisionContext}
     */
    private function buildRevisionOne(): array
    {
        $metadata = $this->metadata;
        $hasMetadata = $metadata !== null;
        $firstObjectNumber = $hasMetadata ? 5 : 3;
        $pagesRef = PdfReference::to(2, 0);

        [$pageAndContentObjects, $pageRefs, $pageHeightsPt, $allWidgets, $acroFormRef] =
            $this->buildPagesFontsImages(firstObjectNumber: $firstObjectNumber, pagesRef: $pagesRef);
        unset($allWidgets);

        $catalogDict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Catalog'))
            ->withEntry(Name::of('Pages'), $pagesRef);
        if ($hasMetadata) {
            $catalogDict = $catalogDict->withEntry(Name::of('Metadata'), PdfReference::to(4, 0));
        }
        if ($acroFormRef !== null) {
            $catalogDict = $catalogDict->withEntry(Name::of('AcroForm'), $acroFormRef);
        }
        $catalogDict = $this->withViewerPrefs($catalogDict, $pageRefs);
        $nextObjectNumber = $firstObjectNumber + count($pageAndContentObjects);
        [$catalogDict, $outlineObjects] = $this->withOutlines($catalogDict, $pageRefs, $pageHeightsPt, $nextObjectNumber);
        $nextObjectNumber += count($outlineObjects);
        $catalogDict = $this->withNames($catalogDict, []);
        [$catalogDict, $structObjects] = $this->withStructTree($catalogDict, $pageRefs, $nextObjectNumber);

        $catalog = IndirectObject::of(1, 0, $catalogDict);
        $pages = IndirectObject::of(
            2,
            0,
            Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Pages'))
                ->withEntry(Name::of('Kids'), PdfArray::of(...$pageRefs))
                ->withEntry(Name::of('Count'), PdfNumber::ofInt(count($this->pages))),
        );

        $objects = [$catalog, $pages];
        $infoRef = null;
        if ($metadata !== null) {
            $effective = clone $metadata;
            $effective->producer ??= 'phppdf ' . self::VERSION;
            $effective->creationDate ??= new DateTimeImmutable();
            $info = IndirectObject::of(3, 0, $this->buildInfoDictionary($effective));
            $metadataStream = IndirectObject::of(4, 0, new MetadataStream((new XmpWriter())->write($effective)));
            $objects[] = $info;
            $objects[] = $metadataStream;
            $infoRef = $info->reference();
            $documentId = $effective->documentId ?? $this->deriveDocumentId($effective);
        } else {
            $documentId = $this->generatedDocumentId ??= md5(random_bytes(16));
        }
        array_push($objects, ...$pageAndContentObjects, ...$outlineObjects, ...$structObjects);

        $firstPageNumber = $pageRefs[0]->objectNumber;
        $firstPage = null;
        $maxObjectNumber = 0;
        foreach ($objects as $o) {
            if ($o->objectNumber > $maxObjectNumber) {
                $maxObjectNumber = $o->objectNumber;
            }
            if ($o->objectNumber === $firstPageNumber) {
                $firstPage = $o;
            }
        }
        if ($firstPage === null) {
            throw new PdfException('Internal: first page object not found while building revision 1');
        }

        $bytes = $this->assembleWithTrailer(
            objects: $objects,
            root: $catalog->reference(),
            info: $infoRef,
            documentId: $documentId,
        );
        if ($this->signature !== null) {
            $bytes = (new SignaturePatcher())->patch($bytes, $this->signature);
        }

        $context = new RevisionContext(
            catalog: $catalog,
            acroForm: $this->findObjectByRef($objects, $acroFormRef),
            firstPage: $firstPage,
            maxObjectNumber: $maxObjectNumber,
            documentId: $documentId,
        );

        return ['bytes' => $bytes, 'context' => $context];
    }

    private function outputWithAppendedRevisions(): string
    {
        ['bytes' => $bytes, 'context' => $ctx] = $this->buildRevisionOne();

        $builder = new AppendedFieldRevisionBuilder();
        foreach ($this->appendedRevisions as $revision) {
            if ($revision instanceof DssRevision) {
                $built = (new DssRevisionBuilder())->build($ctx, $revision->material);
                $prevStartxref = $this->lastStartxrefOffset($bytes);
                $bytes = (new IncrementalWriter())->append(
                    priorBytes: $bytes,
                    newObjects: $built['objects'],
                    root: $ctx->catalog->reference(),
                    documentId: $ctx->documentId,
                    prevStartxref: $prevStartxref,
                    size: $built['size'],
                );
                $ctx = $built['context'];
                continue;
            }

            $built = $builder->build($ctx, $revision->valueDict(...), $revision->fieldName());

            $searchFrom = strlen($bytes);
            $prevStartxref = $this->lastStartxrefOffset($bytes);
            $bytes = (new IncrementalWriter())->append(
                priorBytes: $bytes,
                newObjects: $built['objects'],
                root: $ctx->catalog->reference(),
                documentId: $ctx->documentId,
                prevStartxref: $prevStartxref,
                size: $built['size'],
            );
            $bytes = (new ContentRangePatcher())->patch(
                $bytes,
                $searchFrom,
                $revision->maxSignatureBytes() * 2,
                $revision->fill(...),
            );

            $ctx = $built['context'];
        }

        return $bytes;
    }

    private function lastStartxrefOffset(string $bytes): int
    {
        if (preg_match('~startxref\n(\d+)\n%%EOF\n?$~', $bytes, $m) !== 1) {
            throw new PdfException('Could not locate the revision-1 startxref offset');
        }
        return (int) $m[1];
    }

    /**
     * @param list<IndirectObject> $objects
     */
    private function findObjectByRef(array $objects, ?PdfReference $ref): ?IndirectObject
    {
        if ($ref === null) {
            return null;
        }
        foreach ($objects as $o) {
            if ($o->objectNumber === $ref->objectNumber) {
                return $o;
            }
        }
        return null;
    }

    private function outputEncrypted(Encryption $encryption, ?Metadata $metadata): string
    {
        if ($encryption->userPassword === null || $encryption->ownerPassword === null) {
            throw new PdfException('Both user password and owner password are required for encryption');
        }

        $randomSource = $encryption->randomSource ?? static function (int $n): string {
            if ($n < 1) {
                throw new PdfException('Invalid random byte count: ' . $n);
            }
            return random_bytes($n);
        };

        $cipher = new Cipher();
        $passwordHash = new PasswordHash();
        $encryptionKey = new EncryptionKey(
            userPassword: $encryption->userPassword,
            ownerPassword: $encryption->ownerPassword,
            permissions: $encryption->permissions,
            encryptMetadata: $encryption->encryptMetadata,
            randomSource: $randomSource,
            passwordHash: $passwordHash,
            cipher: $cipher,
        );

        $pagesRef = PdfReference::to(2, 0);
        $hasMetadata = $metadata !== null;

        $objects = [];
        $encryptObjectNumber = $hasMetadata ? 5 : 3;
        $metadataObjectNumber = $hasMetadata ? 4 : null;
        $firstPageObjectNumber = $hasMetadata ? 6 : 4;

        [$pageAndContentObjects, $pageRefs, $pageHeightsPt, $allWidgets, $acroFormRef] = $this->buildPagesFontsImages(
            firstObjectNumber: $firstPageObjectNumber,
            pagesRef: $pagesRef,
        );
        unset($allWidgets); // consumed inside buildPagesFontsImages

        $catalogDict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Catalog'))
            ->withEntry(Name::of('Pages'), $pagesRef);
        if ($hasMetadata) {
            $catalogDict = $catalogDict->withEntry(Name::of('Metadata'), PdfReference::to(4, 0));
        }
        if ($acroFormRef !== null) {
            $catalogDict = $catalogDict->withEntry(Name::of('AcroForm'), $acroFormRef);
        }
        $catalogDict = $this->withViewerPrefs($catalogDict, $pageRefs);

        $nextObjectNumber = $firstPageObjectNumber + count($pageAndContentObjects);
        [$catalogDict, $outlineObjects] = $this->withOutlines($catalogDict, $pageRefs, $pageHeightsPt, $nextObjectNumber);
        $nextObjectNumber += count($outlineObjects);
        $catalogDict = $this->withNames($catalogDict, []);
        [$catalogDict, $structObjects] = $this->withStructTree($catalogDict, $pageRefs, $nextObjectNumber);

        $catalog = IndirectObject::of(1, 0, $catalogDict);
        $objects[] = $catalog;

        $pages = IndirectObject::of(
            2,
            0,
            Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Pages'))
                ->withEntry(Name::of('Kids'), PdfArray::of(...$pageRefs))
                ->withEntry(Name::of('Count'), PdfNumber::ofInt(count($this->pages))),
        );
        $objects[] = $pages;

        $infoRef = null;
        $effectiveMetadata = null;
        if ($metadata !== null) {
            $effectiveMetadata = clone $metadata;
            $effectiveMetadata->producer ??= 'phppdf ' . self::VERSION;
            $effectiveMetadata->creationDate ??= new DateTimeImmutable();

            $infoObject = IndirectObject::of(3, 0, $this->buildInfoDictionary($effectiveMetadata));
            $objects[] = $infoObject;
            $infoRef = $infoObject->reference();

            $xmpXml = (new XmpWriter())->write($effectiveMetadata, $this->pdfALevel, $this->isPdfUA());
            $objects[] = IndirectObject::of(4, 0, new MetadataStream($xmpXml));
        }

        $encryptDict = (new EncryptionDictBuilder())->build(
            $encryptionKey,
            $encryption->encryptMetadata,
            $encryption->permissions,
        );
        $encryptObject = IndirectObject::of($encryptObjectNumber, 0, $encryptDict);
        $objects[] = $encryptObject;

        $objects = array_merge($objects, $pageAndContentObjects, $outlineObjects, $structObjects);

        $documentId = $metadata !== null
            ? ($metadata->documentId ?? $this->deriveDocumentId($effectiveMetadata))
            : bin2hex($randomSource(16));

        $transformer = new ObjectTransformer(
            cipher: $cipher,
            fileKey: $encryptionKey->fileKey(),
            randomSource: $randomSource,
            encryptObjectNumber: $encryptObjectNumber,
            metadataObjectNumber: $metadataObjectNumber,
            encryptMetadata: $encryption->encryptMetadata,
        );

        return (new EncryptedPdfWriter())->write(
            objects: $objects,
            root: $catalog->reference(),
            info: $infoRef,
            encrypt: $encryptObject->reference(),
            documentId: $documentId,
            transformer: $transformer,
        );
    }

    /**
     * Builds:
     *   - page IndirectObjects (with optional /Contents and /Resources entries),
     *   - content-stream IndirectObjects (for pages that drew something),
     *   - font IndirectObjects (one per registered font in the whole doc),
     *   - image and SMask IndirectObjects (for each registered image).
     *
     * All objects share a single numbering starting at $firstObjectNumber.
     *
     * Returns [allObjects, pageRefs, pageHeightsPt, allWidgets, acroFormRef].
     *
     * @return array{0: list<IndirectObject>, 1: list<PdfReference>, 2: list<float>, 3: list<array{field: FormField, widgetRef: PdfReference, pageRef: PdfReference, pageHeightPt: float}>, 4: ?PdfReference}
     */
    private function buildPagesFontsImages(int $firstObjectNumber, PdfReference $pagesRef): array
    {
        $allocator = new PdfObjectAllocator($firstObjectNumber);

        $this->preregisterFormFonts();
        $this->preregisterSvgTextFonts();

        $alloc = $this->allocateObjectNumbers($allocator);
        $pending = $alloc['pending'];
        $pageRefs = $alloc['pageRefs'];
        $pageHeightsPt = $alloc['pageHeightsPt'];
        $linkAnnotationEmitter = $alloc['linkAnnotationEmitter'];
        $fontRefs = $alloc['fontRefs'];
        $customRefs = $alloc['customRefs'];
        $customEmissions = $alloc['customEmissions'];
        $imageRefs = $alloc['imageRefs'];
        $imageEmissions = $alloc['imageEmissions'];

        $pageBuild = (new PageObjectsBuilder(
            allocator: $allocator,
            fontRegistry: $this->fontRegistry,
            fontResolver: $this->fontResolver,
            linkAnnotationEmitter: $linkAnnotationEmitter,
            pagesRef: $pagesRef,
            fontRefs: $fontRefs,
            customRefs: $customRefs,
            imageRefs: $imageRefs,
        ))->build($pending, $pageRefs, $pageHeightsPt);

        $objects = $pageBuild['objects'];
        /** @var list<array{field: FormField, widgetRef: PdfReference, pageRef: PdfReference, pageHeightPt: float}> $allWidgets */
        $allWidgets = $pageBuild['allWidgets'];

        if ($allWidgets === [] && $this->signature !== null) {
            throw new PdfException(sprintf(
                "Signature target field '%s' not found: the document has no form fields",
                $this->signature->fieldName,
            ));
        }

        $acroFormRef = null;
        if ($allWidgets !== []) {
            // Helvetica was pre-registered at the start of this method whenever
            // a page declared a form field, so its short name (and therefore
            // its ref) must exist in $fontRefs.
            $helveticaShortName = $this->fontRegistry->shortName(Font::helvetica());
            if (!isset($fontRefs[$helveticaShortName])) {
                throw new PdfException('Internal: Helvetica not allocated despite form fields being present');
            }
            /** @var array<string, PdfReference> $standardFontRefs alias => reference */
            $standardFontRefs = ['Helv' => $fontRefs[$helveticaShortName]];
            // Map any Courier/Times variant registered for an appearance to its
            // /AcroForm /DR alias. The first variant encountered wins; Acrobat
            // selects the actual face from /DA, not from the /DR alias.
            $aliasByFamilyPrefix = ['Courier' => 'Cour', 'Times' => 'TiRo'];
            foreach ($this->fontRegistry->registeredFonts() as $regFont) {
                $pdfName = $regFont->pdfName();
                foreach ($aliasByFamilyPrefix as $prefix => $alias) {
                    if (isset($standardFontRefs[$alias])) {
                        continue;
                    }
                    if (str_starts_with($pdfName, $prefix)) {
                        $shortName = $this->fontRegistry->shortName($regFont);
                        if (isset($fontRefs[$shortName])) {
                            $standardFontRefs[$alias] = $fontRefs[$shortName];
                        }
                    }
                }
            }

            $acroNextId = $allocator->peek();
            $acroEmit = (new AcroFormEmitter($this->unit))
                ->emit(
                    $allWidgets,
                    $standardFontRefs,
                    $acroNextId,
                    'document acroform',
                    $this->signature,
                    $this->signature !== null ? new SignatureDictionaryEmitter() : null,
                );
            $acroFormRef = $acroEmit['acroFormRef'];
            foreach ($acroEmit['objects'] as $obj) {
                $objects[] = $obj;
            }
        }

        foreach ($this->fontRegistry->registeredFonts() as $font) {
            $shortName = $this->fontRegistry->shortName($font);
            $fontRef = $fontRefs[$shortName];
            $fontDict = Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Font'))
                ->withEntry(Name::of('Subtype'), Name::of('Type1'))
                ->withEntry(Name::of('BaseFont'), Name::of($font->pdfName()))
                ->withEntry(Name::of('Encoding'), Name::of('WinAnsiEncoding'));
            $objects[] = IndirectObject::of($fontRef->objectNumber, 0, $fontDict);
        }

        $embedder = new ImageEmbedder();
        $svgFontRefs = $fontRefs + $customRefs;
        foreach ($imageEmissions as [$image, $imageNum]) {
            foreach ($embedder->embed($image, $imageNum, $this->fontRegistry, $svgFontRefs, $this->fontResolver, $this->svgFilterDpi) as $obj) {
                $objects[] = $obj;
            }
        }

        $objects = array_merge(
            $objects,
            (new SubsettedFontObjectsEmitter($this->glyphUsage))->emit($customEmissions),
        );

        return [$objects, $pageRefs, $pageHeightsPt, $allWidgets, $acroFormRef];
    }

    /**
     * Pre-registers Helvetica if any page has form fields (the /AcroForm dict
     * needs it via /DR /Font /Helv), and any Standard 14 Courier/Times fonts
     * found in FieldAppearance entries (exposed as /Cour and /TiRo). Done
     * before the object-number allocation so these fonts get stable numbers
     * even when no page draws text with them directly.
     */
    private function preregisterFormFonts(): void
    {
        // Pre-register Helvetica if any page has form fields - the /AcroForm
        // dict needs to reference it via /DR /Font /Helv. Done BEFORE the
        // fontRefs allocation loop so Helvetica gets a stable object number
        // even when no page draws text with it. Also scan FieldAppearance
        // entries for any Standard 14 Courier/Times so they get registered
        // (and exposed in /DR /Font as /Cour and /TiRo).
        $hasFormFields = false;
        /** @var array<string, Font> $standardFontsToRegister keyed by pdfName for dedup */
        $standardFontsToRegister = [];
        foreach ($this->pages as $p) {
            $fields = $p->getFormFields();
            if ($fields === []) {
                continue;
            }
            $hasFormFields = true;
            foreach ($fields as $field) {
                $appearance = $field->appearance();
                if ($appearance === null || $appearance->font === null) {
                    continue;
                }
                $font = $appearance->font;
                if ($font->isCustom()) {
                    // Will throw at AcroFormEmitter time with a precise message.
                    continue;
                }
                $standardFontsToRegister[$font->pdfName()] = $font;
            }
        }
        if ($hasFormFields) {
            $this->fontRegistry->shortName(Font::helvetica());
        }
        foreach ($standardFontsToRegister as $font) {
            $this->fontRegistry->shortName($font);
        }
    }

    /**
     * Registers every standard font referenced by SVG <text> in any embedded
     * image, before object-number allocation, so each gets a stable font object
     * (emitted via the standard Type1/WinAnsi path) that the SVG Form can then
     * reference by short name.
     */
    private function preregisterSvgTextFonts(): void
    {
        $aliases = $this->fontResolver?->registeredAliases() ?? [];
        foreach ($this->imageRegistry->registeredImages() as $image) {
            $meta = $image->metadata;
            if (!$meta instanceof SvgMetadata) {
                continue;
            }
            foreach ($meta->textFontSpecs() as $spec) {
                $font = SvgFontResolver::resolve($spec['family'], $spec['bold'], $spec['italic'], $aliases);
                // Route every font through the resolver when present (it registers
                // standard faces too); fall back to a plain short-name allocation
                // when there is no custom-font context.
                if ($this->fontResolver !== null) {
                    $this->fontResolver->resolveEngine($font)->registerOn($this->fontRegistry);
                } else {
                    $this->fontRegistry->shortName($font);
                }
            }
        }
    }

    /**
     * Allocates object numbers up front for pages+contents, standard fonts,
     * custom fonts, and images, in that exact order. Returns the ref maps and
     * emission lists the rest of the serialization consumes.
     *
     * @return array{
     *   pending: list<array{Page, int, ?int}>,
     *   pageRefs: list<PdfReference>,
     *   pageHeightsPt: list<float>,
     *   linkAnnotationEmitter: ?LinkAnnotationEmitter,
     *   fontRefs: array<string, PdfReference>,
     *   customRefs: array<string, PdfReference>,
     *   customEmissions: list<array{ParsedTtf, CustomFontKey, int, int, int, int, int}>,
     *   imageRefs: array<string, PdfReference>,
     *   imageEmissions: list<array{\DragonOfMercy\PhpPdf\Image, int}>
     * }
     */
    private function allocateObjectNumbers(PdfObjectAllocator $allocator): array
    {
        /** @var list<array{Page, int, ?int}> $pending page + its assigned number + optional content number */
        $pending = [];
        $pageRefs = [];
        /** @var list<float> $pageHeightsPt page heights in points, matched 1:1 with $pageRefs. */
        $pageHeightsPt = [];
        $linkAnnotationEmitter = null;
        foreach ($this->pages as $page) {
            $pageNum = $allocator->next();
            $contentNum = $page->contentStream()->isEmpty() ? null : $allocator->next();
            $pending[] = [$page, $pageNum, $contentNum];
            $pageRefs[] = PdfReference::to($pageNum, 0);
            $pageHeightsPt[] = $page->pageHeight;
            if ($linkAnnotationEmitter === null && $page->getLinkAnnotations() !== []) {
                $linkAnnotationEmitter = new LinkAnnotationEmitter($this->unit);
            }
        }

        $fontRefs = [];
        foreach ($this->fontRegistry->registeredFonts() as $font) {
            $fontNum = $allocator->next();
            $shortName = $this->fontRegistry->shortName($font);
            $fontRefs[$shortName] = PdfReference::to($fontNum, 0);
        }

        /** @var array<string, PdfReference> $customRefs short name => Type0 reference */
        $customRefs = [];
        /** @var list<array{ParsedTtf, CustomFontKey, int, int, int, int, int}> $customEmissions */
        $customEmissions = [];
        foreach ($this->fontRegistry->customRegistrations() as $shortName => $key) {
            $type0Id = $allocator->next();
            $cidFontId = $allocator->next();
            $descriptorId = $allocator->next();
            $fontFileId = $allocator->next();
            $toUnicodeId = $allocator->next();

            $parsedTtf = $this->resolveTtfByKey($key);
            $customRefs[$shortName] = PdfReference::to($type0Id, 0);
            $customEmissions[] = [$parsedTtf, $key, $type0Id, $cidFontId, $descriptorId, $fontFileId, $toUnicodeId];
        }

        /** @var array<string, PdfReference> $imageRefs short name => main image reference */
        $imageRefs = [];
        $imageEmissions = [];
        foreach ($this->imageRegistry->registeredImages() as $image) {
            $shortName = $this->imageRegistry->shortName($image);
            $imageNum = $allocator->reserve(ImageEmbedder::objectCount($image));
            $imageRefs[$shortName] = PdfReference::to($imageNum, 0);
            $imageEmissions[] = [$image, $imageNum];
        }

        return [
            'pending' => $pending,
            'pageRefs' => $pageRefs,
            'pageHeightsPt' => $pageHeightsPt,
            'linkAnnotationEmitter' => $linkAnnotationEmitter,
            'fontRefs' => $fontRefs,
            'customRefs' => $customRefs,
            'customEmissions' => $customEmissions,
            'imageRefs' => $imageRefs,
            'imageEmissions' => $imageEmissions,
        ];
    }

    private function resolveTtfByKey(CustomFontKey $key): ParsedTtf
    {
        if (!isset($this->customFontFamilies[$key->alias])) {
            throw new PdfException("Internal error: cannot resolve TTF id {$key->toRegistryKey()}");
        }
        foreach ($this->customFontFamilies[$key->alias] as $variant) {
            if ($variant !== null && $variant->postScriptName === $key->psName) {
                return $variant;
            }
        }
        throw new PdfException("Internal error: cannot resolve TTF id {$key->toRegistryKey()}");
    }

    /**
     * Adds /PageLayout, /PageMode and /OpenAction to the catalog dict when
     * the user has configured them. Page refs are required because /OpenAction
     * targets a specific page by reference.
     *
     * @param list<PdfReference> $pageRefs
     */
    private function withViewerPrefs(Dictionary $catalogDict, array $pageRefs): Dictionary
    {
        if ($this->pageLayout !== null) {
            $catalogDict = $catalogDict->withEntry(
                Name::of('PageLayout'),
                Name::of($this->pageLayout->value),
            );
        }
        if ($this->pageMode !== null) {
            $catalogDict = $catalogDict->withEntry(
                Name::of('PageMode'),
                Name::of($this->pageMode->value),
            );
        }
        if ($this->openAction !== null) {
            $idx = $this->openAction->pageIndex;
            if ($idx < 1 || $idx > count($pageRefs)) {
                throw new PdfException(sprintf(
                    'OpenAction targets page %d but document has %d page(s)',
                    $idx,
                    count($pageRefs),
                ));
            }
            $catalogDict = $catalogDict->withEntry(
                Name::of('OpenAction'),
                $this->openAction->toPdfArray(
                    $pageRefs[$idx - 1],
                    $this->pages[$idx - 1]->pageHeight,
                    $this->unit,
                ),
            );
        }
        if ($this->isPdfUA()) {
            // PDF/UA-1 (ISO 14289-1, 7.1) requires the viewer to display the
            // document title from metadata rather than the file name.
            $viewerPrefs = Dictionary::empty()
                ->withEntry(Name::of('DisplayDocTitle'), PdfBoolean::of(true));
            $catalogDict = $catalogDict->withEntry(Name::of('ViewerPreferences'), $viewerPrefs);
        }
        return $catalogDict;
    }

    /**
     * Runs the OutlineEmitter if an outline with children was declared and
     * appends the resulting /Outlines reference to the catalog dict. When no
     * outline was requested (or the root has no children) the catalog dict
     * is returned untouched and the object list is empty - the user pays
     * nothing for an absent feature.
     *
     * @param  list<PdfReference> $pageRefs
     * @param  list<float>        $pageHeightsPt
     * @return array{0: Dictionary, 1: list<IndirectObject>}
     */
    private function withOutlines(Dictionary $catalogDict, array $pageRefs, array $pageHeightsPt, int &$nextObjectNumber): array
    {
        if ($this->outlineRoot === null || !$this->outlineRoot->hasChildren()) {
            return [$catalogDict, []];
        }
        $emitter = new OutlineEmitter($this->unit);
        $emit = $emitter->emit(
            $this->outlineRoot,
            $pageRefs,
            $pageHeightsPt,
            $nextObjectNumber,
            'document outline',
        );
        $catalogDict = $catalogDict->withEntry(Name::of('Outlines'), $emit['outlinesRef']);
        return [$catalogDict, $emit['objects']];
    }

    /**
     * Serializes the logical structure tree (when tagging is enabled) into
     * indirect objects and wires /StructTreeRoot, /MarkInfo and /Lang into the
     * catalog. When tagging is off the catalog dict is returned untouched and
     * no objects are produced, so the off-path bytes are byte-identical.
     *
     * The emitter numbers its objects starting at $nextObjectNumber; the caller
     * must pass the running counter already advanced past every other
     * dynamically numbered object in that path.
     *
     * @param  list<PdfReference> $pageRefs
     * @return array{0: Dictionary, 1: list<IndirectObject>}
     */
    private function withStructTree(Dictionary $catalogDict, array $pageRefs, int &$nextObjectNumber): array
    {
        if (!$this->taggingEnabled || $this->structureTree === null) {
            return [$catalogDict, []];
        }

        /** @var \SplObjectStorage<\DragonOfMercy\PhpPdf\Outline\LinkAnnotation, int> $emptyLinkMap */
        $emptyLinkMap = new \SplObjectStorage();
        $result = (new StructTreeEmitter())->emit($this->structureTree, $pageRefs, $emptyLinkMap, $nextObjectNumber);
        $nextObjectNumber += count($result->objects);

        $catalogDict = $catalogDict
            ->withEntry(Name::of('StructTreeRoot'), $result->structTreeRootRef)
            ->withEntry(
                Name::of('MarkInfo'),
                Dictionary::empty()->withEntry(Name::of('Marked'), PdfBoolean::of(true)),
            );
        if ($this->language !== null) {
            $catalogDict = $catalogDict->withEntry(Name::of('Lang'), PdfString::of($this->language));
        }

        return [$catalogDict, $result->objects];
    }

    /**
     * Adds a /Names /JavaScript name tree to the catalog for document-level
     * scripts, keys sorted per PDF 32000-1 7.9.6. Returns the catalog unchanged
     * when no document script is registered.
     *
     * The whole structure is inlined directly into the catalog (the name tree is
     * small by nature), so no extra indirect objects are allocated. In the
     * encrypted path the inline /JS strings are encrypted with the catalog
     * object's key like any other string.
     *
     * @param list<array{name: string, ref: PdfReference}> $embeddedFiles
     */
    private function withNames(Dictionary $catalogDict, array $embeddedFiles): Dictionary
    {
        $namesDict = Dictionary::empty();

        if ($this->documentScripts !== []) {
            $scripts = $this->documentScripts;
            ksort($scripts, SORT_STRING);
            $jsItems = [];
            foreach ($scripts as $name => $js) {
                $jsItems[] = PdfString::of($name);
                $jsItems[] = Dictionary::empty()
                    ->withEntry(Name::of('Type'), Name::of('Action'))
                    ->withEntry(Name::of('S'), Name::of('JavaScript'))
                    ->withEntry(Name::of('JS'), PdfString::of($js));
            }
            $namesDict = $namesDict->withEntry(
                Name::of('JavaScript'),
                Dictionary::empty()->withEntry(Name::of('Names'), PdfArray::of(...$jsItems)),
            );
        }

        if ($embeddedFiles !== []) {
            $efItems = [];
            foreach ($embeddedFiles as $ef) {
                $efItems[] = PdfString::of($ef['name']);
                $efItems[] = $ef['ref'];
            }
            $namesDict = $namesDict->withEntry(
                Name::of('EmbeddedFiles'),
                Dictionary::empty()->withEntry(Name::of('Names'), PdfArray::of(...$efItems)),
            );
        }

        if ($this->documentScripts === [] && $embeddedFiles === []) {
            return $catalogDict;
        }
        return $catalogDict->withEntry(Name::of('Names'), $namesDict);
    }

    private static ?string $cachedSrgbIcc = null;

    private static function srgbIccProfile(): string
    {
        if (self::$cachedSrgbIcc === null) {
            $path = __DIR__ . '/../resources/icc/sRGB.icc';
            $data = @file_get_contents($path);
            if ($data === false) {
                throw new PdfException('PDF/A: bundled sRGB ICC profile not found at ' . $path);
            }
            self::$cachedSrgbIcc = $data;
        }
        return self::$cachedSrgbIcc;
    }

    private function buildInfoDictionary(Metadata $m): Dictionary
    {
        $dict = Dictionary::empty();
        if ($m->title !== null) {
            $dict = $dict->withEntry(Name::of('Title'), TextString::of($m->title));
        }
        if ($m->author !== null) {
            $dict = $dict->withEntry(Name::of('Author'), TextString::of($m->author));
        }
        if ($m->subject !== null) {
            $dict = $dict->withEntry(Name::of('Subject'), TextString::of($m->subject));
        }
        if ($m->keywords !== null) {
            $dict = $dict->withEntry(Name::of('Keywords'), TextString::of($m->keywords));
        }
        if ($m->creator !== null) {
            $dict = $dict->withEntry(Name::of('Creator'), TextString::of($m->creator));
        }
        if ($m->producer !== null) {
            $dict = $dict->withEntry(Name::of('Producer'), TextString::of($m->producer));
        }
        if ($m->creationDate !== null) {
            $dict = $dict->withEntry(Name::of('CreationDate'), PdfString::of($this->formatPdfDate($m->creationDate)));
        }
        if ($m->modDate !== null) {
            $dict = $dict->withEntry(Name::of('ModDate'), PdfString::of($this->formatPdfDate($m->modDate)));
        }
        if ($m->trapped !== null) {
            $dict = $dict->withEntry(Name::of('Trapped'), Name::of($m->trapped ? 'True' : 'False'));
        }
        return $dict;
    }

    /**
     * @param list<IndirectObject> $objects
     */
    private function assembleWithTrailer(
        array $objects,
        PdfReference $root,
        ?PdfReference $info,
        string $documentId,
    ): string {
        $xref = new XrefTable();
        $body = self::HEADER;

        foreach ($objects as $object) {
            $xref->recordOffset($object->objectNumber, strlen($body));
            $body .= $object->toBytes();
        }

        $xrefOffset = strlen($body);
        $body .= $xref->toBytes();

        $trailer = new Trailer(
            size: $xref->size(),
            root: $root,
            xrefOffset: $xrefOffset,
            info: $info,
            documentId: $documentId,
        );
        $body .= $trailer->toBytes();

        return $body;
    }

    private function formatPdfDate(DateTimeImmutable $date): string
    {
        return PdfDate::format($date);
    }

    private function deriveDocumentId(Metadata $m): string
    {
        $iso = static fn (?DateTimeImmutable $d): string => $d === null ? '' : $d->format('c');
        $parts = [
            'title:' . ($m->title ?? ''),
            'author:' . ($m->author ?? ''),
            'subject:' . ($m->subject ?? ''),
            'keywords:' . ($m->keywords ?? ''),
            'creator:' . ($m->creator ?? ''),
            'producer:' . ($m->producer ?? ''),
            'creationDate:' . $iso($m->creationDate),
            'modDate:' . $iso($m->modDate),
        ];
        return md5(implode("\x00", $parts));
    }
}
