<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use DragonOfMercy\PhpPdf\Document\Metadata;
use DragonOfMercy\PhpPdf\Document\MetadataStream;
use DragonOfMercy\PhpPdf\Document\PageSetEmitter;
use DragonOfMercy\PhpPdf\Document\XmpWriter;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\FontResolver;
use DragonOfMercy\PhpPdf\Font\Custom\GlyphUsage;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtfCache;
use DragonOfMercy\PhpPdf\Font\Custom\TtfParser;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Form\Fill\FieldTree;
use DragonOfMercy\PhpPdf\Form\Fill\FieldValueApplier;
use DragonOfMercy\PhpPdf\Form\Fill\FormFieldInfo;
use DragonOfMercy\PhpPdf\Form\Fill\FormFieldType;
use DragonOfMercy\PhpPdf\Form\Fill\ResolvedField;
use DragonOfMercy\PhpPdf\Image\ImageRegistry;
use DragonOfMercy\PhpPdf\Modify\PendingChanges;
use DragonOfMercy\PhpPdf\Modify\RevisionWriter;
use DragonOfMercy\PhpPdf\Reader\DictReader;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Signature\AppendedDocumentTimestamp;
use DragonOfMercy\PhpPdf\Signature\AppendedRevision;
use DragonOfMercy\PhpPdf\Signature\AppendedSignature;
use DragonOfMercy\PhpPdf\Signature\DocumentTimestamp;
use DragonOfMercy\PhpPdf\Signature\IncrementalRevisionStacker;
use DragonOfMercy\PhpPdf\Signature\Ltv\CertificateChain;
use DragonOfMercy\PhpPdf\Signature\Ltv\DssRevision;
use DragonOfMercy\PhpPdf\Signature\Ltv\HttpCrlValidationDataSource;
use DragonOfMercy\PhpPdf\Signature\Ltv\ValidationDataSource;
use DragonOfMercy\PhpPdf\Signature\Ltv\ValidationMaterial;
use DragonOfMercy\PhpPdf\Signature\RevisionContext;
use DragonOfMercy\PhpPdf\Signature\Signature;
use DragonOfMercy\PhpPdf\Signature\SignatureAppearance;
use DragonOfMercy\PhpPdf\Signature\SignatureFieldPlan;
use DragonOfMercy\PhpPdf\Signature\SignatureFormat;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfBoolean;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use DragonOfMercy\PhpPdf\Writer\Object\TextString;
use DragonOfMercy\PhpPdf\Writer\PdfObjectAllocator;

/**
 * Opens an existing PDF for modification. Changes are written as an APPENDED
 * incremental revision: the original bytes stay byte-for-byte intact, which
 * preserves any existing digital signatures. Encrypted files are rejected.
 *
 * Known limitation: appended pages carry their own /MediaBox, /Resources and
 * /Rotate 0 so nothing harmful is inherited from the existing tree; an
 * inherited /CropBox smaller than that box could still apply, which is
 * harmless in v1.
 */
final class Pdf
{
    private readonly PdfReader $reader;
    private readonly string $bytes;
    private PendingChanges $pending;

    /** @var list<AppendedRevision|DssRevision> */
    private array $appendedRevisions = [];

    /** @var list<SigningCertificate> */
    private array $signingCertificates = [];
    private bool $ltvEnabled = false;

    /** @var array<string, SignatureAppearance> */
    private array $signatureAppearances = [];

    private readonly Unit $unit;
    private readonly FontRegistry $fontRegistry;
    private readonly MetricsRegistry $metricsRegistry;
    private readonly ImageRegistry $imageRegistry;
    private readonly GlyphUsage $glyphUsage;
    private ?FontResolver $fontResolver = null;
    private ?FieldTree $fieldTree = null;
    /** @var list<FormFieldInfo>|null Cached introspection snapshot of the source form (the source /V values never change after open). */
    private ?array $formFieldsCache = null;

    /** @var array<string, array{regular: ParsedTtf, bold: ?ParsedTtf, italic: ?ParsedTtf, boldItalic: ?ParsedTtf}> */
    private array $customFontFamilies = [];

    private PageFormat $lastFormat = PageFormat::A4;
    /** @var array{float, float}|null Custom dimensions [w, h] in user unit; takes precedence over $lastFormat when set. */
    private ?array $lastCustom = null;
    private Orientation $lastOrientation = Orientation::PORTRAIT;

    private function __construct(string $bytes)
    {
        if (!str_starts_with($bytes, '%PDF-')) {
            throw new PdfException('Cannot modify a PDF whose %PDF header is not at byte 0; re-save the file first');
        }
        $this->reader = PdfReader::fromBytes($bytes);
        $this->bytes = $bytes;
        $this->pending = new PendingChanges();

        // Appended pages are built with the document unit (points) and a fresh
        // set of registries; the existing tree is never reparsed into them.
        $this->unit = Unit::PT;
        $this->glyphUsage = new GlyphUsage();
        $this->fontRegistry = new FontRegistry();
        $this->metricsRegistry = new MetricsRegistry();
        $this->imageRegistry = new ImageRegistry();
    }

    public static function open(string $path): self
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new PdfException("Cannot read PDF file: {$path}");
        }
        return new self($bytes);
    }

    public static function fromBytes(string $bytes): self
    {
        return new self($bytes);
    }

    public function setTitle(string $title): self
    {
        $this->pending->title = $title;
        return $this;
    }

    public function setAuthor(string $author): self
    {
        $this->pending->author = $author;
        return $this;
    }

    public function setSubject(string $subject): self
    {
        $this->pending->subject = $subject;
        return $this;
    }

    public function setKeywords(string $keywords): self
    {
        $this->pending->keywords = $keywords;
        return $this;
    }

    public function setCreator(string $creator): self
    {
        $this->pending->creator = $creator;
        return $this;
    }

    /**
     * Registers a custom TTF font family by alias for use on appended pages.
     * The regular variant is required; bold/italic/boldItalic are optional and
     * fall back to regular. Files are read and parsed eagerly (mirrors
     * {@see Document::registerFontFamily}).
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
        $this->customFontFamilies[$alias] = [
            'regular' => $this->parseFontFile($alias, 'regular', $regular),
            'bold' => $bold !== null ? $this->parseFontFile($alias, 'bold', $bold) : null,
            'italic' => $italic !== null ? $this->parseFontFile($alias, 'italic', $italic) : null,
            'boldItalic' => $boldItalic !== null ? $this->parseFontFile($alias, 'boldItalic', $boldItalic) : null,
        ];
        $this->fontResolver = new FontResolver(
            $this->customFontFamilies,
            $this->metricsRegistry,
            $this->glyphUsage,
        );
        return $this;
    }

    /**
     * Appends a new page to the document. Returns a real {@see Page} with the
     * full writing API. The page is emitted in the appended revision at
     * {@see output()}; existing pages stay untouched. Size resolution mirrors
     * {@see Document::addPage} (default A4 portrait).
     *
     * @param PageFormat|array{float|int, float|int}|null $format
     */
    public function appendPage(PageFormat|array|null $format = null, ?Orientation $orientation = null): Page
    {
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

        // document: null keeps header/footer/tagging machinery off; the page is
        // a standalone surface emitted by PageSetEmitter.
        $page = new Page(
            pageWidth: $widthPoints,
            pageHeight: $heightPoints,
            fontRegistry: $this->fontRegistry,
            metricsRegistry: $this->metricsRegistry,
            imageRegistry: $this->imageRegistry,
            unit: $this->unit,
            defaultFont: null,
            defaultSize: null,
            defaultCellsPadding: null,
            fontResolver: $this->fontResolver,
            margins: null,
            document: null,
        );
        $page->setPageIndex(count($this->pending->pages));
        $this->pending->pages[] = $page;
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

    private function fieldTree(): FieldTree
    {
        return $this->fieldTree ??= new FieldTree($this->reader);
    }

    /**
     * Returns a snapshot of every terminal AcroForm field discovered in the
     * opened PDF, with inheritance applied and the current /V decoded into a
     * PHP-native value.
     *
     * @return list<FormFieldInfo>
     */
    public function formFields(): array
    {
        if ($this->formFieldsCache !== null) {
            return $this->formFieldsCache;
        }
        $out = [];
        foreach ($this->fieldTree()->terminalFields() as $rf) {
            $out[] = new FormFieldInfo(
                name: $rf->name,
                type: $rf->type,
                value: $this->currentValueOf($rf),
                options: $rf->options,
                readOnly: $rf->isReadOnly(),
                required: ($rf->flags & 2) !== 0,
                multiline: $rf->isMultiline(),
            );
        }
        return $this->formFieldsCache = $out;
    }

    /**
     * Returns the {@see FormFieldInfo} for the field with the given fully-
     * qualified name, or null when no such field exists.
     */
    public function field(string $name): ?FormFieldInfo
    {
        foreach ($this->formFields() as $info) {
            if ($info->name === $name) {
                return $info;
            }
        }
        return null;
    }

    /**
     * Queues a field-value edit for output. Deep validation (option membership,
     * radio export names, etc.) happens at {@see output()} time via
     * FieldValueApplier. This method does LIGHT fail-fast checks only:
     *
     * - Unknown field name: throws with a Levenshtein-based "did you mean" hint.
     * - PushButton / Signature: always rejected (carry no user value).
     * - Wrong PHP type for the field category (string vs bool vs array).
     * - Read-only guard: rejected unless $force is true.
     *
     * Last write wins: calling setField() twice for the same name keeps the
     * second value.
     *
     * @param string|bool|list<string> $value
     */
    public function setField(string $name, string|bool|array $value, bool $force = false): self
    {
        $allFields = $this->fieldTree()->terminalFields();

        $resolved = null;
        foreach ($allFields as $rf) {
            if ($rf->name === $name) {
                $resolved = $rf;
                break;
            }
        }

        if ($resolved === null) {
            if ($allFields === []) {
                throw new PdfException('This PDF has no AcroForm fields to fill');
            }
            $suggestions = [];
            foreach ($allFields as $rf) {
                $suggestions[] = [$rf->name, levenshtein($name, $rf->name)];
            }
            usort($suggestions, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
            $top = array_slice($suggestions, 0, 3);
            $hint = implode(', ', array_column($top, 0));
            throw new PdfException("Unknown form field '{$name}'. Did you mean: {$hint}?");
        }

        if ($resolved->type === FormFieldType::PushButton || $resolved->type === FormFieldType::Signature) {
            throw new PdfException("Field '{$name}' is a {$resolved->type->name} and cannot be filled");
        }

        if ($resolved->type === FormFieldType::Text || $resolved->type === FormFieldType::Combobox || $resolved->type === FormFieldType::Radio) {
            if (!is_string($value)) {
                throw new PdfException("Field '{$name}' expects a string value");
            }
        } elseif ($resolved->type === FormFieldType::Checkbox) {
            if (!is_bool($value)) {
                throw new PdfException("Field '{$name}' expects a bool value");
            }
        } elseif ($resolved->type === FormFieldType::Listbox) {
            if (!is_string($value) && !is_array($value)) {
                throw new PdfException("Field '{$name}' expects a string or array of strings");
            }
        }

        if ($resolved->isReadOnly() && !$force) {
            throw new PdfException("Field '{$name}' is read-only; pass force: true to fill it anyway");
        }

        $this->pending->fieldEdits[$name] = $value;

        return $this;
    }

    /**
     * Decodes the merged /V entry of a resolved field into its PHP-native form:
     *
     * - Text / Combobox : string|null         (decoded TextString/PdfString/HexString)
     * - Checkbox        : bool                (true iff /V is a Name != 'Off')
     * - Radio           : string|null         (export name, null when absent or 'Off')
     * - Listbox         : string|list<string>|null
     * - PushButton / Signature: null
     *
     * @return string|bool|list<string>|null
     */
    private function currentValueOf(ResolvedField $rf): string|bool|array|null
    {
        $raw = $rf->dict->get(Name::of('V'));

        if ($rf->type === FormFieldType::Text || $rf->type === FormFieldType::Combobox) {
            return DictReader::decodeText($raw !== null ? $this->reader->resolve($raw) : null);
        }

        if ($rf->type === FormFieldType::Checkbox) {
            if ($raw === null) {
                return false;
            }
            $resolved = $this->reader->resolve($raw);
            return $resolved instanceof Name && $resolved->value() !== 'Off';
        }

        if ($rf->type === FormFieldType::Radio) {
            if ($raw === null) {
                return null;
            }
            $resolved = $this->reader->resolve($raw);
            if (!$resolved instanceof Name) {
                return null;
            }
            $exportName = $resolved->value();
            return $exportName !== 'Off' ? $exportName : null;
        }

        if ($rf->type === FormFieldType::Listbox) {
            return $this->decodeListboxValue($raw);
        }

        // PushButton, Signature
        return null;
    }

    /**
     * Decodes a Listbox /V entry: PdfArray -> list<string>, text string -> string, absent -> null.
     *
     * @return string|list<string>|null
     */
    private function decodeListboxValue(?PdfObject $raw): string|array|null
    {
        if ($raw === null) {
            return null;
        }
        $resolved = $this->reader->resolve($raw);
        if ($resolved instanceof PdfArray) {
            /** @var list<string> $items */
            $items = [];
            foreach ($resolved->elements() as $element) {
                $text = DictReader::decodeText($this->reader->resolve($element));
                if ($text !== null) {
                    $items[] = $text;
                }
            }
            return $items !== [] ? $items : null;
        }
        return DictReader::decodeText($resolved);
    }

    public function addDocumentTimestamp(Tsa $tsa, int $maxSignatureBytes = 16384): self
    {
        $name = 'DocTimeStamp' . (count($this->appendedRevisions) + 1);
        $this->appendedRevisions[] = new AppendedDocumentTimestamp(
            new DocumentTimestamp($tsa, $maxSignatureBytes),
            $name,
        );
        return $this;
    }

    /**
     * Makes the opened PDF's signatures long-term validatable. Identical contract
     * to {@see Document::enableLtv}: collects the signer certificate chains plus
     * their CRLs/OCSPs into a /DSS and (when a timestamp is given) covers them
     * with a document timestamp, appended as the last incremental revisions so
     * they cover every signature. Validation material is gathered eagerly (a
     * network failure surfaces here, not mid-output). Must be called after
     * sign()/addSignature(); once-only.
     *
     * @param list<list<string>> $timestampCertificateChains PEM chains whose
     *        revocation is added so a covering document timestamp is itself
     *        long-term validatable (B-LTA).
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
            $this->addDocumentTimestamp($timestamp, 16384);
        }
        return $this;
    }

    /**
     * Queues a cryptographic signature (PKCS#7 / CMS) over the opened PDF. The
     * signature is written as a stacked incremental revision at output() that
     * covers all prior bytes (including any pending metadata / page / field
     * edits). $field names the signature field: a new invisible /FT /Sig field
     * with that name is created on the first page (reusing an existing empty
     * field of that name comes in a later task). Mirrors Document::sign().
     */
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
        ?SignatureAppearance $appearance = null,
    ): self {
        $this->queueSignature($field, $certificate, $reason, $location, $contactInfo,
            $signedAt, $maxSignatureBytes, $timestamp, $format, $appearance);
        return $this;
    }

    /**
     * Queues an additional signature on an auto-named field (Signature1,
     * Signature2, ...). Mirrors Document::addSignature().
     */
    public function addSignature(
        SigningCertificate $certificate,
        ?string $reason = null,
        ?string $location = null,
        ?string $contactInfo = null,
        ?\DateTimeImmutable $signedAt = null,
        int $maxSignatureBytes = 16384,
        ?Tsa $timestamp = null,
        SignatureFormat $format = SignatureFormat::Pkcs7Detached,
        ?SignatureAppearance $appearance = null,
    ): self {
        $name = 'Signature' . (count($this->appendedRevisions) + 1);
        $this->queueSignature($name, $certificate, $reason, $location, $contactInfo,
            $signedAt, $maxSignatureBytes, $timestamp, $format, $appearance);
        return $this;
    }

    private function queueSignature(
        string $field,
        SigningCertificate $certificate,
        ?string $reason,
        ?string $location,
        ?string $contactInfo,
        ?\DateTimeImmutable $signedAt,
        int $maxSignatureBytes,
        ?Tsa $timestamp,
        SignatureFormat $format,
        ?SignatureAppearance $appearance,
    ): void {
        $signature = new Signature(
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
        $this->appendedRevisions[] = new AppendedSignature($signature);
        $this->signingCertificates[] = $certificate;
        if ($appearance !== null) {
            $this->signatureAppearances[$field] = $appearance;
        }
    }

    public function output(): string
    {
        if ($this->pending->isEmpty() && $this->appendedRevisions === []) {
            throw new PdfException('No pending changes to write; call a setter, appendPage(), setField(), or a signing method first');
        }

        if ($this->appendedRevisions === []) {
            return $this->assembleRevision();
        }

        ['bytes' => $bytes, 'context' => $ctx] = $this->buildSigningBase();
        $plans = $this->resolveSignatureFields();
        return (new IncrementalRevisionStacker())->stack($bytes, $ctx, $this->appendedRevisions, $plans);
    }

    /**
     * For each queued signature, decides how its field is realized and validates:
     * - appearance != null -> create a VISIBLE field on the chosen page; if a
     *   field of that name already exists, throw (a visible signature creates a
     *   new field).
     * - appearance == null + existing empty /FT /Sig field -> reuse it.
     * - appearance == null + non-signature field           -> throw.
     * - appearance == null + already-signed field          -> throw.
     * - appearance == null + no field                      -> create invisible
     *   (no plan entry).
     *
     * @return array<string, SignatureFieldPlan>
     */
    private function resolveSignatureFields(): array
    {
        $plans = [];
        $terminals = [];
        foreach ($this->fieldTree()->terminalFields() as $rf) {
            $terminals[$rf->name] = $rf;
        }
        foreach ($this->appendedRevisions as $rev) {
            if (!$rev instanceof AppendedSignature) {
                continue;
            }
            $name = $rev->fieldName();
            $appearance = $this->signatureAppearances[$name] ?? null;
            $rf = $terminals[$name] ?? null;

            if ($appearance !== null) {
                if ($rf !== null) {
                    throw new PdfException("A field named '{$name}' already exists; omit the appearance to sign it in place, or use a new field name for a visible signature");
                }
                $page = $this->pageObjectAt($appearance->pageIndex);
                $rect = $this->appearanceRect($appearance, $page);
                $plans[$name] = SignatureFieldPlan::visible($page, $rect, $appearance);
                continue;
            }

            if ($rf === null) {
                continue;
            }
            if ($rf->type !== FormFieldType::Signature) {
                throw new PdfException("Field '{$name}' exists and is not a signature field; choose a different field name");
            }
            if ($rf->dict->get(Name::of('V')) !== null) {
                throw new PdfException("Field '{$name}' is already signed");
            }
            $this->guardGenerationZero($rf->objectNumber, "signature field '{$name}'");
            $plans[$name] = SignatureFieldPlan::reuse(IndirectObject::of($rf->objectNumber, 0, $rf->dict));
        }
        return $plans;
    }

    /**
     * Converts an appearance box (document unit, y top-down) to a PDF /Rect
     * [llx, lly, urx, ury] in points, flipped against the target page MediaBox.
     *
     * @return list<float>
     */
    private function appearanceRect(SignatureAppearance $appearance, IndirectObject $page): array
    {
        $mediaBox = $this->pageMediaBox($page);
        $llx = $mediaBox[0] + $appearance->x;
        $urx = $mediaBox[0] + $appearance->x + $appearance->width;
        $ury = $mediaBox[3] - $appearance->y;
        $lly = $mediaBox[3] - $appearance->y - $appearance->height;
        return [$llx, $lly, $urx, $ury];
    }

    /**
     * Reads the /MediaBox of a page object (corner-normalized [llx,lly,urx,ury]
     * in points). Library-emitted pages always carry their own /MediaBox.
     *
     * @return list<float>
     */
    private function pageMediaBox(IndirectObject $page): array
    {
        $raw = $page->dictionaryPayload()->get(Name::of('MediaBox'));
        $resolved = $raw !== null ? $this->reader->resolve($raw) : null;
        if (!$resolved instanceof PdfArray || count($resolved->elements()) !== 4) {
            throw new PdfException('Cannot place a visible signature: the target page has no usable /MediaBox');
        }
        $out = [];
        foreach ($resolved->elements() as $el) {
            $n = $this->reader->resolve($el);
            if (!$n instanceof PdfNumber) {
                throw new PdfException('Target page /MediaBox contains a non-numeric element');
            }
            $out[] = (float) $n->value();
        }
        return $out;
    }

    public function save(string $path): void
    {
        if (@file_put_contents($path, $this->output()) === false) {
            throw new PdfException("Cannot write PDF file: {$path}");
        }
    }

    private function assembleRevision(): string
    {
        ['newObjects' => $newObjects, 'trailerEntries' => $trailerEntries, 'nextNumber' => $nextNumber]
            = $this->assembleRevisionObjects();

        return (new RevisionWriter())->append(
            reader: $this->reader,
            priorBytes: $this->bytes,
            newObjects: $newObjects,
            trailerEntries: $trailerEntries,
            size: $nextNumber,
        );
    }

    /**
     * Builds the indirect objects + trailer entries for the edit revision
     * (metadata / appended pages / filled fields), without serializing.
     *
     * @return array{newObjects: list<IndirectObject>, trailerEntries: Dictionary, nextNumber: int}
     */
    private function assembleRevisionObjects(): array
    {
        $newObjects = [];
        $nextNumber = $this->reader->maxObjectNumber() + 1;

        $rootRef = $this->reader->trailer()->get(Name::of('Root'));
        if (!$rootRef instanceof PdfReference) {
            throw new PdfException('The opened PDF has no indirect /Root reference');
        }
        $trailerEntries = Dictionary::empty()->withEntry(Name::of('Root'), $rootRef);

        // /Info: merge the source entries with the pending setters, reusing the
        // source object number when /Info exists
        $sourceInfoRef = $this->reader->trailer()->get(Name::of('Info'));
        $infoNumber = $sourceInfoRef instanceof PdfReference ? $sourceInfoRef->objectNumber : $nextNumber++;
        if ($sourceInfoRef instanceof PdfReference) {
            $this->guardGenerationZero($sourceInfoRef->objectNumber, '/Info');
        }
        $newObjects[] = IndirectObject::of($infoNumber, 0, $this->mergedInfoDictionary());
        $trailerEntries = $trailerEntries->withEntry(Name::of('Info'), PdfReference::to($infoNumber, 0));

        // /ID carried through verbatim when present
        $id = $this->reader->trailer()->get(Name::of('ID'));
        if ($id !== null) {
            $trailerEntries = $trailerEntries->withEntry(Name::of('ID'), $id);
        }

        // XMP refresh + catalog re-emit when the catalog carries /Metadata
        $catalogMetadata = $this->reader->catalog()->get(Name::of('Metadata'));
        if ($catalogMetadata instanceof PdfReference) {
            $xmpNumber = $nextNumber++;
            $newObjects[] = IndirectObject::of($xmpNumber, 0, new MetadataStream($this->refreshedXmp()));
            $this->guardGenerationZero($rootRef->objectNumber, '/Root');
            $newObjects[] = IndirectObject::of(
                $rootRef->objectNumber,
                0,
                $this->reader->catalog()->withEntry(Name::of('Metadata'), PdfReference::to($xmpNumber, 0)),
            );
        }

        if ($this->pending->pages !== []) {
            $nextNumber = $this->emitAppendedPages($newObjects, $nextNumber);
        }

        if ($this->pending->fieldEdits !== []) {
            $nextNumber = $this->emitFilledFields($newObjects, $nextNumber);
        }

        // $nextNumber starts at maxObjectNumber() + 1 and only ever advances
        // (Info/XMP increments + the monotonic page allocator), so it is already
        // the next free object number the revision's xref must announce as /Size.
        return ['newObjects' => $newObjects, 'trailerEntries' => $trailerEntries, 'nextNumber' => $nextNumber];
    }

    /**
     * Produces the bytes a signature revision is stacked onto, plus the
     * RevisionContext describing the catalog / AcroForm / first page / next
     * object number / document id at that point. When there are pending edits
     * they are written as the first incremental revision; otherwise the source
     * bytes are returned unchanged.
     *
     * @return array{bytes: string, context: RevisionContext}
     */
    private function buildSigningBase(): array
    {
        $rootRef = $this->reader->trailer()->get(Name::of('Root'));
        if (!$rootRef instanceof PdfReference) {
            throw new PdfException('The opened PDF has no indirect /Root reference');
        }

        $acroFormEntry = $this->reader->catalog()->get(Name::of('AcroForm'));
        if ($acroFormEntry instanceof Dictionary) {
            throw new PdfException('Cannot sign this PDF: its /AcroForm is a direct dictionary; only an indirect /AcroForm reference is supported');
        }

        $hasEdits = !$this->pending->isEmpty();

        if ($hasEdits) {
            ['newObjects' => $newObjects, 'trailerEntries' => $trailerEntries, 'nextNumber' => $nextNumber]
                = $this->assembleRevisionObjects();
            $bytes = (new RevisionWriter())->append(
                reader: $this->reader,
                priorBytes: $this->bytes,
                newObjects: $newObjects,
                trailerEntries: $trailerEntries,
                size: $nextNumber,
            );
            $catalog = $this->latestObject($newObjects, $rootRef->objectNumber)
                ?? IndirectObject::of($rootRef->objectNumber, 0, $this->reader->catalog());
            $acroForm = $this->latestAcroForm($newObjects);
            $maxObjectNumber = $nextNumber - 1;
        } else {
            $bytes = $this->bytes;
            $catalog = IndirectObject::of($rootRef->objectNumber, 0, $this->reader->catalog());
            $acroForm = $this->latestAcroForm([]);
            $maxObjectNumber = $this->reader->maxObjectNumber();
        }

        $ctx = new RevisionContext(
            catalog: $catalog,
            acroForm: $acroForm,
            firstPage: $this->pageObjectAt(0),
            maxObjectNumber: $maxObjectNumber,
            documentId: $this->sourceDocumentId(),
        );

        return ['bytes' => $bytes, 'context' => $ctx];
    }

    /**
     * Returns the re-emitted version of $objectNumber from $newObjects, or null.
     *
     * @param list<IndirectObject> $newObjects
     */
    private function latestObject(array $newObjects, int $objectNumber): ?IndirectObject
    {
        $found = null;
        foreach ($newObjects as $o) {
            if ($o->objectNumber === $objectNumber) {
                $found = $o; // last write wins
            }
        }
        return $found;
    }

    /**
     * The latest AcroForm IndirectObject: re-emitted in the edit revision if the
     * field-fill path touched it, else read from the source catalog (null when
     * the source has no /AcroForm).
     *
     * @param list<IndirectObject> $newObjects
     */
    private function latestAcroForm(array $newObjects): ?IndirectObject
    {
        $acroRef = $this->reader->catalog()->get(Name::of('AcroForm'));
        if ($acroRef instanceof PdfReference) {
            $latest = $this->latestObject($newObjects, $acroRef->objectNumber);
            if ($latest !== null) {
                return $latest;
            }
            $resolved = $this->reader->resolve($acroRef);
            return $resolved instanceof Dictionary
                ? IndirectObject::of($acroRef->objectNumber, 0, $resolved)
                : null;
        }
        return null;
    }

    /**
     * Builds the appended pages' objects via the shared {@see PageSetEmitter}
     * and re-emits the source /Pages root with the new kids appended and
     * /Count increased. Returns the next free object number.
     *
     * @param list<IndirectObject> $newObjects
     */
    private function emitAppendedPages(array &$newObjects, int $nextNumber): int
    {
        $pagesRootRef = $this->reader->catalog()->get(Name::of('Pages'));
        if (!$pagesRootRef instanceof PdfReference) {
            throw new PdfException('The opened PDF has no indirect /Pages reference');
        }
        $this->guardGenerationZero($pagesRootRef->objectNumber, '/Pages');

        $allocator = new PdfObjectAllocator($nextNumber);
        $emitter = new PageSetEmitter(
            fontRegistry: $this->fontRegistry,
            fontResolver: $this->fontResolver,
            imageRegistry: $this->imageRegistry,
            svgFilterDpi: 300,
            glyphUsage: $this->glyphUsage,
            unit: $this->unit,
            customFontFamilies: $this->customFontFamilies,
        );
        $build = $emitter->emit($this->pending->pages, $allocator, $pagesRootRef);
        $pageObjectNumbers = [];
        foreach ($build['pageRefs'] as $pageRef) {
            $pageObjectNumbers[$pageRef->objectNumber] = true;
        }
        foreach ($build['objects'] as $object) {
            $newObjects[] = $this->withRotateZeroOnPages($object, $pageObjectNumbers);
        }

        // rewrite the source /Pages root: kids + count
        $rootDict = $this->reader->resolve($pagesRootRef);
        if (!$rootDict instanceof Dictionary) {
            throw new PdfException('/Pages does not resolve to a dictionary');
        }
        $kids = $this->reader->resolve($rootDict->get(Name::of('Kids')) ?? PdfNull::instance());
        $kidElements = $kids instanceof PdfArray ? $kids->elements() : [];
        foreach ($build['pageRefs'] as $pageRef) {
            $kidElements[] = $pageRef;
        }
        $count = DictReader::int($rootDict, 'Count', $this->reader->resolve(...)) ?? count($kidElements);
        $newObjects[] = IndirectObject::of(
            $pagesRootRef->objectNumber,
            0,
            $rootDict
                ->withEntry(Name::of('Kids'), PdfArray::of(...$kidElements))
                ->withEntry(Name::of('Count'), PdfNumber::ofInt($count + count($this->pending->pages))),
        );

        return $allocator->peek();
    }

    /**
     * Returns an appended page object extended with /Rotate 0 (so no rotation
     * is inherited from the existing tree); non-page objects pass through.
     *
     * @param array<int, true> $pageObjectNumbers
     */
    private function withRotateZeroOnPages(IndirectObject $object, array $pageObjectNumbers): IndirectObject
    {
        if (!isset($pageObjectNumbers[$object->objectNumber])) {
            return $object;
        }
        return IndirectObject::of(
            $object->objectNumber,
            $object->generation,
            $object->dictionaryPayload()->withEntry(Name::of('Rotate'), PdfNumber::ofInt(0)),
        );
    }

    private function mergedInfoDictionary(): Dictionary
    {
        $dict = Dictionary::empty();
        $source = $this->reader->trailer()->get(Name::of('Info'));
        if ($source !== null) {
            $resolved = $this->reader->resolve($source);
            if ($resolved instanceof Dictionary) {
                $dict = $resolved;
            }
        }
        foreach ($this->pendingInfoEntries() as $key => $value) {
            $dict = $dict->withEntry(Name::of($key), TextString::of($value));
        }
        return $dict;
    }

    /** @return array<string, string> */
    private function pendingInfoEntries(): array
    {
        return array_filter([
            'Title' => $this->pending->title,
            'Author' => $this->pending->author,
            'Subject' => $this->pending->subject,
            'Keywords' => $this->pending->keywords,
            'Creator' => $this->pending->creator,
        ], static fn (?string $v): bool => $v !== null);
    }

    /**
     * Rebuilds the XMP packet from the MERGED metadata (source /Info plus the
     * pending setters, which win). Source dates are not carried in v1 - the
     * XmpWriter tolerates absent dates.
     */
    private function refreshedXmp(): string
    {
        $merged = $this->mergedInfoDictionary();
        $metadata = new Metadata();
        $apply = static function (string $key, callable $set) use ($merged): void {
            $value = $merged->get(Name::of($key));
            $text = $value !== null ? DictReader::decodeText($value) : null;
            if ($text !== null) {
                $set($text);
            }
        };
        $apply('Title', $metadata->title(...));
        $apply('Author', $metadata->author(...));
        $apply('Subject', $metadata->subject(...));
        $apply('Keywords', $metadata->keywords(...));
        $apply('Creator', $metadata->creator(...));
        return (new XmpWriter())->write($metadata);
    }

    /**
     * Re-emits each edited AcroForm field (and its widgets / generated
     * appearance streams) into the appended revision, and re-emits the
     * /AcroForm dictionary with /NeedAppearances false so viewers trust the
     * generated appearances. Returns the next free object number.
     *
     * @param list<IndirectObject> $newObjects
     */
    private function emitFilledFields(array &$newObjects, int $nextNumber): int
    {
        // Build a name -> ResolvedField map for fast lookup.
        $byName = [];
        foreach ($this->fieldTree()->terminalFields() as $rf) {
            $byName[$rf->name] = $rf;
        }

        $allocate = function () use (&$nextNumber): int {
            return $nextNumber++;
        };

        $applier = new FieldValueApplier($this->reader, $this->metricsRegistry);

        // Collect re-emitted objects keyed by object number (last write wins so
        // a field re-emitted twice does not appear twice in the revision).
        /** @var array<int, IndirectObject> $emitted */
        $emitted = [];

        foreach ($this->pending->fieldEdits as $name => $value) {
            $rf = $byName[$name] ?? null;
            if ($rf === null) {
                throw new PdfException("Cannot fill unknown field '{$name}'");
            }
            $this->guardGenerationZero($rf->objectNumber, "field '{$name}'");
            $applied = $applier->apply($rf, $value, $allocate);
            foreach ($applied->objects as $obj) {
                $emitted[$obj->objectNumber] = $obj;
            }
        }

        foreach ($emitted as $obj) {
            $newObjects[] = $obj;
        }

        // Re-emit /AcroForm with /NeedAppearances false so viewers trust
        // the generated appearance streams.
        $acroRef = $this->reader->catalog()->get(Name::of('AcroForm'));
        if ($acroRef instanceof PdfReference) {
            $this->guardGenerationZero($acroRef->objectNumber, '/AcroForm');
            $acroResolved = $this->reader->resolve($acroRef);
            if (!$acroResolved instanceof Dictionary) {
                throw new PdfException('/AcroForm does not resolve to a Dictionary');
            }
            $dict = $acroResolved->withEntry(Name::of('NeedAppearances'), PdfBoolean::false());
            $newObjects[] = IndirectObject::of($acroRef->objectNumber, 0, $dict);
        } else {
            // Inline AcroForm dict: re-emit the catalog/Root object with the
            // updated inline AcroForm entry.
            $rootRef = $this->reader->trailer()->get(Name::of('Root'));
            if (!$rootRef instanceof PdfReference) {
                throw new PdfException('The opened PDF has no indirect /Root reference');
            }
            $this->guardGenerationZero($rootRef->objectNumber, '/Root (inline AcroForm)');
            $catalog = $this->reader->catalog();
            if ($acroRef instanceof Dictionary) {
                $updatedAcro = $acroRef->withEntry(Name::of('NeedAppearances'), PdfBoolean::false());
                $newObjects[] = IndirectObject::of(
                    $rootRef->objectNumber,
                    0,
                    $catalog->withEntry(Name::of('AcroForm'), $updatedAcro),
                );
            } else {
                throw new PdfException('/AcroForm is neither an indirect reference nor a dictionary; cannot set /NeedAppearances');
            }
        }

        return $nextNumber;
    }

    private function guardGenerationZero(int $objectNumber, string $what): void
    {
        $generation = $this->reader->generationOf($objectNumber);
        if ($generation !== 0) {
            throw new PdfException("Cannot rewrite {$what} (object {$objectNumber}): generation {$generation} is not supported");
        }
    }

    /**
     * Resolves the IndirectObject (number + dictionary) of the 0-based page at
     * $index by descending the source page tree, resolving intermediate
     * /Type /Pages nodes. Used to place a signature widget on a chosen page.
     */
    private function pageObjectAt(int $index): IndirectObject
    {
        $refs = $this->pageReferences();
        if ($index < 0 || $index >= count($refs)) {
            throw new PdfException('Cannot place signature on page ' . $index . ' (document has ' . count($refs) . ' pages)');
        }
        $ref = $refs[$index];
        $this->guardGenerationZero($ref->objectNumber, "page {$index}");
        $dict = $this->reader->resolve($ref);
        if (!$dict instanceof Dictionary) {
            throw new PdfException("Page object {$ref->objectNumber} does not resolve to a dictionary");
        }
        return IndirectObject::of($ref->objectNumber, 0, $dict);
    }

    /**
     * Flattens the source page tree to the ordered list of leaf /Type /Page
     * references.
     *
     * @return list<PdfReference>
     */
    private function pageReferences(): array
    {
        $pagesRef = $this->reader->catalog()->get(Name::of('Pages'));
        if (!$pagesRef instanceof PdfReference) {
            throw new PdfException('The opened PDF has no indirect /Pages reference');
        }
        $out = [];
        $this->collectPageRefs($pagesRef, $out, 0);
        if ($out === []) {
            throw new PdfException('The opened PDF has no pages');
        }
        return $out;
    }

    /**
     * @param list<PdfReference> $out
     */
    private function collectPageRefs(PdfReference $nodeRef, array &$out, int $depth): void
    {
        if ($depth > 50) {
            throw new PdfException('Page tree nested too deeply (possible cycle)');
        }
        $node = $this->reader->resolve($nodeRef);
        if (!$node instanceof Dictionary) {
            return;
        }
        $type = $node->get(Name::of('Type'));
        if ($type instanceof Name && $type->value() === 'Pages') {
            $kids = $this->reader->resolve($node->get(Name::of('Kids')) ?? PdfNull::instance());
            if ($kids instanceof PdfArray) {
                foreach ($kids->elements() as $kid) {
                    if ($kid instanceof PdfReference) {
                        $this->collectPageRefs($kid, $out, $depth + 1);
                    }
                }
            }
            return;
        }
        // Leaf: a node whose /Type is not /Pages is treated as a page (real Page
        // objects sometimes omit /Type; intermediate nodes always declare /Pages).
        $out[] = $nodeRef;
    }

    /**
     * Hex content for the incremental revisions' trailer /ID, preserving the
     * source /ID[0] when present (HexString -> its digits; literal -> bin2hex),
     * else a deterministic md5 of the source bytes.
     */
    private function sourceDocumentId(): string
    {
        $id = $this->reader->trailer()->get(Name::of('ID'));
        if ($id instanceof PdfArray) {
            $first = $id->elements()[0] ?? null;
            if ($first instanceof HexString) {
                return $first->hex();
            }
            if ($first instanceof PdfString) {
                return bin2hex($first->value());
            }
        }
        return md5($this->bytes);
    }
}
