<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use Closure;
use DateTimeImmutable;
use DragonOfMercy\PhpPdf\Document\Encryption;
use DragonOfMercy\PhpPdf\Document\Metadata;
use DragonOfMercy\PhpPdf\Document\MetadataStream;
use DragonOfMercy\PhpPdf\Document\XmpWriter;
use DragonOfMercy\PhpPdf\Encryption\Cipher;
use DragonOfMercy\PhpPdf\Encryption\EncryptedPdfWriter;
use DragonOfMercy\PhpPdf\Encryption\EncryptionDictBuilder;
use DragonOfMercy\PhpPdf\Encryption\EncryptionKey;
use DragonOfMercy\PhpPdf\Encryption\ObjectTransformer;
use DragonOfMercy\PhpPdf\Encryption\PasswordHash;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\Cff\CffOpenTypeSubsetter;
use DragonOfMercy\PhpPdf\Font\Custom\CompositeFontEmitter;
use DragonOfMercy\PhpPdf\Font\Custom\CustomFontKey;
use DragonOfMercy\PhpPdf\Font\Custom\FontResolver;
use DragonOfMercy\PhpPdf\Font\Custom\GlyphClosure;
use DragonOfMercy\PhpPdf\Font\Custom\GlyphUsage;
use DragonOfMercy\PhpPdf\Font\Custom\OpenTypeFontEmitter;
use DragonOfMercy\PhpPdf\Font\Custom\OutlineFormat;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\Custom\SubsetTag;
use DragonOfMercy\PhpPdf\Font\Custom\SubsettedFont;
use DragonOfMercy\PhpPdf\Font\Custom\TtfParser;
use DragonOfMercy\PhpPdf\Font\Custom\TtfSubsetter;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Image\ImageEmbedder;
use DragonOfMercy\PhpPdf\Image\ImageRegistry;
use DragonOfMercy\PhpPdf\Writer\Object\CompressedStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use DragonOfMercy\PhpPdf\Writer\Object\TextString;
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
            try {
                ($this->header)($page);
            } finally {
                $page->inHeaderRender = false;
                $page->restoreFontState($savedFontState);
            }
        }
        // Position cursor at the top-left of the content area (inside margins).
        $page->setXY($this->margins->left, $this->margins->top);
        $this->pages[] = $page;
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

        $this->runFooters();

        if ($this->encryption !== null) {
            return $this->outputEncrypted($this->encryption, $this->metadata);
        }

        return $this->metadata === null
            ? $this->outputWithoutMetadata()
            : $this->outputWithMetadata($this->metadata);
    }

    private function runFooters(): void
    {
        // Guard against re-entry: footer callbacks emit into page content
        // streams, so repeated output() calls would otherwise duplicate them.
        if ($this->footer === null || $this->footersRendered) {
            return;
        }
        $this->footersRendered = true;
        $totalPages = count($this->pages);
        $previousCurrent = $this->currentPage;
        try {
            foreach ($this->pages as $i => $page) {
                $this->currentPage = $page;
                $savedFontState = $page->captureFontState();
                try {
                    ($this->footer)($page, $i + 1, $totalPages);
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

    private function outputWithoutMetadata(): string
    {
        $pagesRef = PdfReference::to(2, 0);

        [$pageAndContentObjects, $pageRefs] = $this->buildPagesFontsImages(firstObjectNumber: 3, pagesRef: $pagesRef);

        $catalogDict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Catalog'))
            ->withEntry(Name::of('Pages'), $pagesRef);
        $catalogDict = $this->withViewerPrefs($catalogDict, $pageRefs);
        $catalog = IndirectObject::of(1, 0, $catalogDict);

        $pages = IndirectObject::of(
            2,
            0,
            Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Pages'))
                ->withEntry(Name::of('Kids'), PdfArray::of(...$pageRefs))
                ->withEntry(Name::of('Count'), PdfNumber::ofInt(count($this->pages))),
        );

        return (new PdfWriter())->write([$catalog, $pages, ...$pageAndContentObjects], $catalog->reference());
    }

    private function outputWithMetadata(Metadata $metadata): string
    {
        $effective = clone $metadata;
        $effective->producer ??= 'phppdf ' . self::VERSION;
        $effective->creationDate ??= new DateTimeImmutable();

        $pagesRef = PdfReference::to(2, 0);
        $metadataStreamRef = PdfReference::to(4, 0);

        [$pageAndContentObjects, $pageRefs] = $this->buildPagesFontsImages(firstObjectNumber: 5, pagesRef: $pagesRef);

        $catalogDict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Catalog'))
            ->withEntry(Name::of('Pages'), $pagesRef)
            ->withEntry(Name::of('Metadata'), $metadataStreamRef);
        $catalogDict = $this->withViewerPrefs($catalogDict, $pageRefs);
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

        $xmpXml = (new XmpWriter())->write($effective);
        $metadataStream = IndirectObject::of(4, 0, new MetadataStream($xmpXml));

        $objects = [$catalog, $pages, $info, $metadataStream, ...$pageAndContentObjects];

        $documentId = $effective->documentId ?? $this->deriveDocumentId($effective);

        return $this->assembleWithTrailer(
            objects: $objects,
            root: $catalog->reference(),
            info: $info->reference(),
            documentId: $documentId,
        );
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

        [$pageAndContentObjects, $pageRefs] = $this->buildPagesFontsImages(
            firstObjectNumber: $firstPageObjectNumber,
            pagesRef: $pagesRef,
        );

        $catalogDict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Catalog'))
            ->withEntry(Name::of('Pages'), $pagesRef);
        if ($hasMetadata) {
            $catalogDict = $catalogDict->withEntry(Name::of('Metadata'), PdfReference::to(4, 0));
        }
        $catalogDict = $this->withViewerPrefs($catalogDict, $pageRefs);
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

            $xmpXml = (new XmpWriter())->write($effectiveMetadata);
            $objects[] = IndirectObject::of(4, 0, new MetadataStream($xmpXml));
        }

        $encryptDict = (new EncryptionDictBuilder())->build(
            $encryptionKey,
            $encryption->encryptMetadata,
            $encryption->permissions,
        );
        $encryptObject = IndirectObject::of($encryptObjectNumber, 0, $encryptDict);
        $objects[] = $encryptObject;

        $objects = array_merge($objects, $pageAndContentObjects);

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
     * Returns [allObjects, pageRefs].
     *
     * @return array{list<IndirectObject>, list<PdfReference>}
     */
    private function buildPagesFontsImages(int $firstObjectNumber, PdfReference $pagesRef): array
    {
        $objects = [];
        $pageRefs = [];
        $nextObjectNumber = $firstObjectNumber;

        /** @var list<array{Page, int, ?int}> $pending page + its assigned number + optional content number */
        $pending = [];
        foreach ($this->pages as $page) {
            $pageNum = $nextObjectNumber++;
            $contentNum = $page->contentStream()->isEmpty() ? null : $nextObjectNumber++;
            $pending[] = [$page, $pageNum, $contentNum];
            $pageRefs[] = PdfReference::to($pageNum, 0);
        }

        $fontRefs = [];
        foreach ($this->fontRegistry->registeredFonts() as $font) {
            $fontNum = $nextObjectNumber++;
            $shortName = $this->fontRegistry->shortName($font);
            $fontRefs[$shortName] = PdfReference::to($fontNum, 0);
        }

        /** @var array<string, PdfReference> $customRefs short name => Type0 reference */
        $customRefs = [];
        /** @var list<array{ParsedTtf, CustomFontKey, int, int, int, int, int}> $customEmissions */
        $customEmissions = [];
        foreach ($this->fontRegistry->customRegistrations() as $shortName => $key) {
            $type0Id = $nextObjectNumber++;
            $cidFontId = $nextObjectNumber++;
            $descriptorId = $nextObjectNumber++;
            $fontFileId = $nextObjectNumber++;
            $toUnicodeId = $nextObjectNumber++;

            $parsedTtf = $this->resolveTtfByKey($key);
            $customRefs[$shortName] = PdfReference::to($type0Id, 0);
            $customEmissions[] = [$parsedTtf, $key, $type0Id, $cidFontId, $descriptorId, $fontFileId, $toUnicodeId];
        }

        /** @var array<string, PdfReference> $imageRefs short name => main image reference */
        $imageRefs = [];
        $imageEmissions = [];
        foreach ($this->imageRegistry->registeredImages() as $image) {
            $shortName = $this->imageRegistry->shortName($image);
            $imageNum = $nextObjectNumber;
            $imageRefs[$shortName] = PdfReference::to($imageNum, 0);
            $nextObjectNumber += ImageEmbedder::objectCount($image);
            $imageEmissions[] = [$image, $imageNum];
        }

        foreach ($pending as [$page, $pageNum, $contentNum]) {
            $pageDict = Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Page'))
                ->withEntry(Name::of('Parent'), $pagesRef)
                ->withEntry(Name::of('MediaBox'), PdfArray::of(
                    PdfNumber::ofInt(0),
                    PdfNumber::ofInt(0),
                    PdfNumber::ofFloat($page->pageWidth),
                    PdfNumber::ofFloat($page->pageHeight),
                ));

            $pageFonts = $page->fontsUsed();
            $pageImages = $page->imagesUsed();

            // /Resources is REQUIRED on /Page per PDF 1.7 spec 7.7.3.3 (an
            // empty dictionary is valid; omitting it means "inherit from a
            // /Pages ancestor", which we do not emit). qpdf --check warns
            // ("Resources is missing or invalid; repairing") when this is
            // absent, even though Adobe and browsers silently tolerate it.
            $resources = Dictionary::empty();
            if ($pageFonts !== []) {
                $fontDict = Dictionary::empty();
                foreach ($pageFonts as $font) {
                    if ($font->isCustom()) {
                        if ($this->fontResolver === null) {
                            throw new PdfException('Custom font used without registered family');
                        }
                        $resolvedTtf = $this->fontResolver->resolve($font);
                        $key = new CustomFontKey(
                            $font->requireCustomAlias(),
                            $resolvedTtf->postScriptName,
                        );
                        $shortName = $this->fontRegistry->shortNameForCustom($font, $key);
                        $fontDict = $fontDict->withEntry(Name::of($shortName), $customRefs[$shortName]);
                    } else {
                        $shortName = $this->fontRegistry->shortName($font);
                        $fontDict = $fontDict->withEntry(Name::of($shortName), $fontRefs[$shortName]);
                    }
                }
                $resources = $resources->withEntry(Name::of('Font'), $fontDict);
            }
            if ($pageImages !== []) {
                $xObjectDict = Dictionary::empty();
                foreach ($pageImages as $imageShort) {
                    $xObjectDict = $xObjectDict->withEntry(
                        Name::of($imageShort),
                        $imageRefs[$imageShort],
                    );
                }
                $resources = $resources->withEntry(Name::of('XObject'), $xObjectDict);
            }
            $pageDict = $pageDict->withEntry(Name::of('Resources'), $resources);

            if ($contentNum !== null) {
                $pageDict = $pageDict->withEntry(
                    Name::of('Contents'),
                    PdfReference::to($contentNum, 0),
                );
                $objects[] = IndirectObject::of($pageNum, 0, $pageDict);
                $objects[] = IndirectObject::of(
                    $contentNum,
                    0,
                    CompressedStream::of($page->contentStream()->bytes()),
                );
            } else {
                $objects[] = IndirectObject::of($pageNum, 0, $pageDict);
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
        foreach ($imageEmissions as [$image, $imageNum]) {
            foreach ($embedder->embed($image, $imageNum) as $obj) {
                $objects[] = $obj;
            }
        }

        if ($customEmissions !== []) {
            $ttfEmitter = new CompositeFontEmitter();
            $otfEmitter = new OpenTypeFontEmitter();
            $cffSubsetter = new CffOpenTypeSubsetter();
            foreach ($customEmissions as [$parsed, $key, $t0, $cf, $desc, $ff, $tu]) {
                $context = $parsed->postScriptName;
                $used = $this->glyphUsage->usedGids($key->toRegistryKey());
                if ($parsed->outlineFormat === OutlineFormat::Cff) {
                    // CFF outlines: GID-preserving subset of CharStrings INDEX only
                    // (closure = used GIDs + notdef GID 0). All other CFF tables are
                    // copied verbatim by CffWriter; FontFile3 carries the rebuilt
                    // sfnt and BaseFont/FontName get the deterministic subset tag.
                    $closure = $used + [0 => true];
                    $sortedGids = array_keys($closure);
                    sort($sortedGids);
                    $subsetBytes = $cffSubsetter->subset($parsed->bytes, $closure, $context);
                    $tag = SubsetTag::derive($context, $sortedGids);
                    $subset = new SubsettedFont($subsetBytes, $tag . '+' . $context);
                    $emitted = $otfEmitter->emit($subset, $parsed, $t0, $cf, $desc, $ff, $tu);
                } else {
                    // TrueType outlines: GID-preserving subset + derived tag (Phase 3b path).
                    $closure = GlyphClosure::expand($parsed->bytes, $used, $context);
                    $sortedGids = array_keys($closure);
                    sort($sortedGids); // makes tag derivation independent of GlyphClosure's internal insertion order
                    $subsetBytes = TtfSubsetter::subset($parsed->bytes, $closure, $context);
                    $tag = SubsetTag::derive($context, $sortedGids);
                    $subset = new SubsettedFont($subsetBytes, $tag . '+' . $context);
                    $emitted = $ttfEmitter->emit($parsed, $subset, $t0, $cf, $desc, $ff, $tu);
                }
                $objects[] = $emitted['type0'];
                $objects[] = $emitted['cidFont'];
                $objects[] = $emitted['descriptor'];
                $objects[] = $emitted['fontFile'];
                $objects[] = $emitted['toUnicode'];
            }
        }

        return [$objects, $pageRefs];
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
        return $catalogDict;
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
        PdfReference $info,
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
        $base = $date->format('\D\:YmdHis');
        $offset = $date->getOffset();
        if ($offset === 0) {
            return $base . 'Z';
        }
        $sign = $offset >= 0 ? '+' : '-';
        $h = intdiv(abs($offset), 3600);
        $m = intdiv(abs($offset) % 3600, 60);
        return $base . sprintf("%s%02d'%02d", $sign, $h, $m);
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
