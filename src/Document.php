<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use Closure;
use DateTimeImmutable;
use DragonOfMercy\PhpPdf\Document\Encryption;
use DragonOfMercy\PhpPdf\Document\Metadata;
use DragonOfMercy\PhpPdf\Document\MetadataStream;
use DragonOfMercy\PhpPdf\Document\PageSetEmitter;
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
use DragonOfMercy\PhpPdf\Form\FormField;
use DragonOfMercy\PhpPdf\Font\Custom\FontResolver;
use DragonOfMercy\PhpPdf\Font\Custom\GlyphUsage;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtfCache;
use DragonOfMercy\PhpPdf\Font\Custom\TtfParser;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Image\ImageRegistry;
use DragonOfMercy\PhpPdf\Import\ImportedPageTemplate;
use DragonOfMercy\PhpPdf\Import\ImportedPdf;
use DragonOfMercy\PhpPdf\Outline\OutlineEmitter;
use DragonOfMercy\PhpPdf\Outline\OutlineNode;
use DragonOfMercy\PhpPdf\Page\ColumnLayout;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Signature\AppendedDocumentTimestamp;
use DragonOfMercy\PhpPdf\Signature\AppendedRevision;
use DragonOfMercy\PhpPdf\Signature\AppendedSignature;
use DragonOfMercy\PhpPdf\Signature\DocumentTimestamp;
use DragonOfMercy\PhpPdf\Signature\IncrementalRevisionStacker;
use DragonOfMercy\PhpPdf\Signature\Ltv\DssRevision;
use DragonOfMercy\PhpPdf\Signature\Ltv\HttpCrlValidationDataSource;
use DragonOfMercy\PhpPdf\Signature\Ltv\LtvMaterialCollector;
use DragonOfMercy\PhpPdf\Signature\Ltv\ValidationDataSource;
use DragonOfMercy\PhpPdf\Signature\RevisionContext;
use DragonOfMercy\PhpPdf\Signature\Signature;
use DragonOfMercy\PhpPdf\Signature\SignatureFormat;
use DragonOfMercy\PhpPdf\Signature\SignaturePatcher;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Tagging\StructTreeEmitter;
use DragonOfMercy\PhpPdf\Tagging\StructureTree;
use DragonOfMercy\PhpPdf\Tagging\TaggingConformanceGuard;
use DragonOfMercy\PhpPdf\Text\Direction;
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

    /** Default margin (in the document's unit) applied by setAutoPageBreak(true) when current margins are zero. */
    private const float DEFAULT_AUTO_BREAK_MARGIN = 20.0;

    /** Initial default border line width (in the document's unit) applied to Border factories without an explicit withWidth() call. */
    private const float INITIAL_DEFAULT_BORDER_WIDTH = 0.25;

    /** @var list<Page> */
    private array $pages = [];

    private readonly FontRegistry $fontRegistry;
    private readonly MetricsRegistry $metricsRegistry;
    private readonly ImageRegistry $imageRegistry;

    /** @var array<int, array{0: string, 1: ImportedPageTemplate}> keyed by spl_object_id */
    private array $importedTemplates = [];

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

    private Direction $baseDirection = Direction::LTR;

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

    /**
     * Parses an existing PDF file so its pages can be drawn as templates
     * via Page::template(). Encrypted sources are decrypted in place using the
     * supplied password (empty string for an empty user password).
     */
    public function importPdf(string $path, ?string $password = null): ImportedPdf
    {
        return new ImportedPdf(PdfReader::fromFile($path, $password));
    }

    /** Same as importPdf() but from in-memory bytes. */
    public function importPdfBytes(string $bytes, ?string $password = null): ImportedPdf
    {
        return new ImportedPdf(PdfReader::fromBytes($bytes, $password));
    }

    /**
     * Assigns (or returns) the document-wide short name for an imported page
     * template (Tpl1, Tpl2, ...).
     *
     * @internal
     */
    public function registerTemplate(ImportedPageTemplate $template): string
    {
        $id = spl_object_id($template);
        if (!isset($this->importedTemplates[$id])) {
            $shortName = 'Tpl' . (count($this->importedTemplates) + 1);
            $this->importedTemplates[$id] = [$shortName, $template];
        }
        return $this->importedTemplates[$id][0];
    }

    /**
     * @internal
     * @return array<string, ImportedPageTemplate> short name => template
     */
    public function registeredTemplates(): array
    {
        $byName = [];
        foreach ($this->importedTemplates as [$shortName, $template]) {
            $byName[$shortName] = $template;
        }
        return $byName;
    }

    private function parseFontFile(string $alias, string $variant, string $path): ParsedTtf
    {
        if (!is_file($path)) {
            throw new PdfException("Font file not found for alias '{$alias}' ({$variant}): {$path}");
        }
        return ParsedTtfCache::getOrParse($path, function () use ($alias, $variant, $path): ParsedTtf {
            $bytes = @file_get_contents($path);
            if ($bytes === false) {
                throw new PdfException("Cannot read font file for alias '{$alias}' ({$variant}): {$path}");
            }
            return TtfParser::parse($bytes, "{$alias} ({$variant})");
        });
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
     * Default text direction for the document. Pages and cells inherit it; a
     * per-call direction: argument or a per-Cell direction overrides it. AUTO
     * derives the base from the first strong character of each text run.
     */
    public function setBaseDirection(Direction $direction): self
    {
        $this->baseDirection = $direction;
        return $this;
    }

    public function baseDirection(): Direction
    {
        return $this->baseDirection;
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
        // Validation material is gathered eagerly (here, not at output()) so a
        // network failure surfaces at the enableLtv() call rather than mid-output().
        $material = LtvMaterialCollector::collect($resolver, $this->signingCertificates, $timestampCertificateChains);
        $this->appendedRevisions[] = new DssRevision($material);

        if ($timestamp !== null) {
            $this->appendDocumentTimestamp($timestamp, 16384);
        }
        return $this;
    }

    /**
     * Makes output() emit a PDF/A conformant file at the given level (ISO 19005
     * part 2 or 3, conformance B / U / A). Forces the metadata output path so the
     * XMP packet, Info dictionary, and document /ID are always present, embeds an
     * sRGB output intent, and stamps the pdfaid schema. Throws at output() if the
     * document uses a non-embedded standard font, encryption, document scripts, or
     * appended revisions.
     *
     * Conformance level A additionally requires a tagged logical structure tree,
     * so this method calls enableTagging($lang) for level-A levels (A2A / A3A);
     * $lang is the catalog /Lang (e.g. 'en-US'). For B / U levels $lang is
     * ignored. To produce a document that is both PDF/A-2a and PDF/UA-1, call
     * enablePdfUA() as well (order-independent).
     */
    public function enablePdfA(PdfALevel $level, ?string $lang = null): self
    {
        $this->pdfALevel = $level;
        if ($level->requiresTagging()) {
            $this->enableTagging($lang);
        }

        return $this;
    }

    /**
     * Turns on tagged-PDF output: the high-level API (cell, image, table,
     * markdown) accumulates a logical structure tree that output() serializes
     * into a StructTreeRoot. The optional language tag (e.g. 'en-US') is written
     * to the catalog /Lang. Off by default; when off, output is byte-identical
     * to an untagged document. A null $lang leaves any previously set language
     * untouched, so composing enableTagging / enablePdfA / enablePdfUA stays
     * order-independent for the language.
     */
    public function enableTagging(?string $lang = null): self
    {
        if ($lang !== null && preg_match('/^[A-Za-z]{1,8}(-[A-Za-z0-9]{1,8})*$/', $lang) !== 1) {
            throw new PdfException("Invalid language tag for enableTagging, got '{$lang}'");
        }
        $this->taggingEnabled = true;
        $this->language = $lang ?? $this->language;
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
                $this->lastCustom = PageSizeResolver::validateCustom($format);
            } else {
                $this->lastFormat = $format;
                $this->lastCustom = null;
            }
        }
        if ($orientation !== null) {
            $this->lastOrientation = $orientation;
        }

        [$widthPoints, $heightPoints] = PageSizeResolver::toPoints(
            $this->lastCustom,
            $this->lastFormat,
            $this->lastOrientation,
            $this->unit,
        );

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
                hasForbiddenAttachments: !$this->pdfALevel->allowsEmbeddedFiles() && $this->attachments !== [],
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
                hasUntaggedLinkAnnotations: $this->hasUntaggedLinkAnnotations(),
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

    /**
     * True when any page declares an untagged link annotation (one created via
     * Page::link() rather than the tagged cell(link: ...) API). Such links carry
     * no /StructParent and break PDF/UA, so the conformance guard rejects them.
     */
    private function hasUntaggedLinkAnnotations(): bool
    {
        foreach ($this->pages as $page) {
            foreach ($page->getLinkAnnotations() as $annotation) {
                if (!$annotation->isTagged()) {
                    return true;
                }
            }
        }
        return false;
    }

    private function outputWithoutMetadata(): string
    {
        $pagesRef = PdfReference::to(2, 0);

        [$pageAndContentObjects, $pageRefs, $pageHeightsPt, $allWidgets, $acroFormRef, $linkAnnotationMap] = $this->buildPagesFontsImages(firstObjectNumber: 3, pagesRef: $pagesRef);
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
        [$catalogDict, $structObjects] = $this->withStructTree($catalogDict, $pageRefs, $nextObjectNumber, $linkAnnotationMap);

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

        // PDF/A-4 (ISO 19005-4:2020 clause 6.1.3) forbids the /Info key in the
        // trailer dictionary. Metadata lives entirely in the XMP stream. When the
        // Info object is dropped we reclaim its slot: the metadata stream takes
        // obj 3 and page objects start at obj 4, keeping object numbers contiguous.
        $omitsInfo = $this->pdfALevel?->omitsInfoDictionary() ?? false;

        $pagesRef = PdfReference::to(2, 0);
        $metadataStreamRef = $omitsInfo ? PdfReference::to(3, 0) : PdfReference::to(4, 0);
        $firstPageObjectNumber = $omitsInfo ? 4 : 5;

        [$pageAndContentObjects, $pageRefs, $pageHeightsPt, $allWidgets, $acroFormRef, $linkAnnotationMap] = $this->buildPagesFontsImages(firstObjectNumber: $firstPageObjectNumber, pagesRef: $pagesRef);
        unset($allWidgets); // consumed inside buildPagesFontsImages

        $catalogDict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Catalog'))
            ->withEntry(Name::of('Pages'), $pagesRef)
            ->withEntry(Name::of('Metadata'), $metadataStreamRef);
        if ($acroFormRef !== null) {
            $catalogDict = $catalogDict->withEntry(Name::of('AcroForm'), $acroFormRef);
        }
        $catalogDict = $this->withViewerPrefs($catalogDict, $pageRefs);

        $nextObjectNumber = $firstPageObjectNumber + count($pageAndContentObjects);
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
                iccBytes: self::srgbIccProfile($this->pdfALevel->usesV2OutputIntentProfile()),
            );
            $outputIntentObjects = [$intent, $profile];
            $catalogDict = $catalogDict->withEntry(
                Name::of('OutputIntents'),
                PdfArray::of(PdfReference::to($intentNumber, 0)),
            );
            $afterOutlines += count($outputIntentObjects);
        }

        [$catalogDict, $structObjects] = $this->withStructTree($catalogDict, $pageRefs, $afterOutlines, $linkAnnotationMap);

        $catalog = IndirectObject::of(1, 0, $catalogDict);

        $pages = IndirectObject::of(
            2,
            0,
            Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Pages'))
                ->withEntry(Name::of('Kids'), PdfArray::of(...$pageRefs))
                ->withEntry(Name::of('Count'), PdfNumber::ofInt(count($this->pages))),
        );

        $xmpXml = (new XmpWriter())->write($effective, $this->pdfALevel, $this->isPdfUA());
        $metadataStream = IndirectObject::of($metadataStreamRef->objectNumber, 0, new MetadataStream($xmpXml));

        $documentId = $effective->documentId ?? $this->deriveDocumentId($effective);

        // PDF/A-4 drops /Info entirely; every other document keeps it at obj 3.
        $info = $omitsInfo ? null : IndirectObject::of(3, 0, $this->buildInfoDictionary($effective));
        $infoObjects = $info !== null ? [$info] : [];
        $objects = [$catalog, $pages, ...$infoObjects, $metadataStream, ...$pageAndContentObjects, ...$outlineObjects, ...$attachmentObjects, ...$outputIntentObjects, ...$structObjects];

        return $this->assembleWithTrailer(
            objects: $objects,
            root: $catalog->reference(),
            info: $info?->reference(),
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

        [$pageAndContentObjects, $pageRefs, $pageHeightsPt, $allWidgets, $acroFormRef, $linkAnnotationMap] =
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
        [$catalogDict, $structObjects] = $this->withStructTree($catalogDict, $pageRefs, $nextObjectNumber, $linkAnnotationMap);

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
        return (new IncrementalRevisionStacker())->stack($bytes, $ctx, $this->appendedRevisions);
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

        [$pageAndContentObjects, $pageRefs, $pageHeightsPt, $allWidgets, $acroFormRef, $linkAnnotationMap] = $this->buildPagesFontsImages(
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
        [$catalogDict, $structObjects] = $this->withStructTree($catalogDict, $pageRefs, $nextObjectNumber, $linkAnnotationMap);

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
     * Thin wrapper around {@see PageSetEmitter}: builds every page, content,
     * font, image (and AcroForm) indirect object, all sharing a single
     * numbering starting at $firstObjectNumber.
     *
     * Returns [allObjects, pageRefs, pageHeightsPt, allWidgets, acroFormRef, linkAnnotationMap].
     *
     * @return array{0: list<IndirectObject>, 1: list<PdfReference>, 2: list<float>, 3: list<array{field: FormField, widgetRef: PdfReference, pageRef: PdfReference, pageHeightPt: float}>, 4: ?PdfReference, 5: \SplObjectStorage<\DragonOfMercy\PhpPdf\Outline\LinkAnnotation, int>}
     */
    private function buildPagesFontsImages(int $firstObjectNumber, PdfReference $pagesRef): array
    {
        $emitter = new PageSetEmitter(
            fontRegistry: $this->fontRegistry,
            fontResolver: $this->fontResolver,
            imageRegistry: $this->imageRegistry,
            svgFilterDpi: $this->svgFilterDpi,
            glyphUsage: $this->glyphUsage,
            unit: $this->unit,
            customFontFamilies: $this->customFontFamilies,
            signature: $this->signature,
            importedTemplates: $this->registeredTemplates(),
            forbidsTransparency: $this->pdfALevel?->forbidsTransparency() ?? false,
            requiresCidSet: $this->pdfALevel?->requiresCidSet() ?? false,
        );
        $emit = $emitter->emit($this->pages, new PdfObjectAllocator($firstObjectNumber), $pagesRef);

        return [
            $emit['objects'],
            $emit['pageRefs'],
            $emit['pageHeightsPt'],
            $emit['allWidgets'],
            $emit['acroFormRef'],
            $emit['linkAnnotationMap'],
        ];
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
     * @param  list<PdfReference>                                                  $pageRefs
     * @param  \SplObjectStorage<\DragonOfMercy\PhpPdf\Outline\LinkAnnotation, int> $linkAnnotationMap each emitted link annotation to its object number (empty storage when no links)
     * @return array{0: Dictionary, 1: list<IndirectObject>}
     */
    private function withStructTree(Dictionary $catalogDict, array $pageRefs, int &$nextObjectNumber, \SplObjectStorage $linkAnnotationMap): array
    {
        if (!$this->taggingEnabled || $this->structureTree === null) {
            return [$catalogDict, []];
        }

        $result = (new StructTreeEmitter())->emit($this->structureTree, $pageRefs, $linkAnnotationMap, $nextObjectNumber);
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

    /** @var array<string, string> filename => cached ICC bytes */
    private static array $cachedIcc = [];

    /**
     * Returns the bundled sRGB ICC profile for the OutputIntent. PDF/A parts 2-4
     * embed an ICC v4 sRGB profile; PDF/A-1 (validated against the ICC v2 colour
     * model) requires a v2 matrix/TRC profile instead (see
     * PdfALevel::usesV2OutputIntentProfile). The bundled v2 profile (sRGB-v2.icc)
     * is from saucecontrol/Compact-ICC-Profiles, published under CC0-1.0 (public domain).
     */
    private static function srgbIccProfile(bool $v2 = false): string
    {
        $file = $v2 ? 'sRGB-v2.icc' : 'sRGB.icc';
        if (!isset(self::$cachedIcc[$file])) {
            $path = __DIR__ . '/../resources/icc/' . $file;
            $data = @file_get_contents($path);
            if ($data === false) {
                throw new PdfException('PDF/A: bundled sRGB ICC profile not found at ' . $path);
            }
            self::$cachedIcc[$file] = $data;
        }
        return self::$cachedIcc[$file];
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
     * The %PDF-x.y header line plus the mandatory binary-comment line. The
     * version follows the active PDF/A level (PDF/A-4 is PDF 2.0), defaulting to
     * 1.7 for every other document so existing output stays byte-identical.
     */
    private function pdfHeaderBytes(): string
    {
        $version = $this->pdfALevel?->headerVersion() ?? '1.7';
        return '%PDF-' . $version . "\n%\xE2\xE3\xCF\xD3\n";
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
        $body = $this->pdfHeaderBytes();

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
