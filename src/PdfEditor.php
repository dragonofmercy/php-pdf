<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use DragonOfMercy\PhpPdf\Document\PageSetEmitter;
use DragonOfMercy\PhpPdf\Encryption\Reader\IncrementalObjectEncryptor;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\CustomFontFamilyRegistrar;
use DragonOfMercy\PhpPdf\Font\Custom\FontResolver;
use DragonOfMercy\PhpPdf\Font\Custom\GlyphUsage;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Form\Fill\FieldTree;
use DragonOfMercy\PhpPdf\Form\Fill\FormFieldInfo;
use DragonOfMercy\PhpPdf\Form\Fill\FieldValueDecoder;
use DragonOfMercy\PhpPdf\Form\Fill\FormFieldType;
use DragonOfMercy\PhpPdf\Form\Fill\ResolvedField;
use DragonOfMercy\PhpPdf\Image\ImageRegistry;
use DragonOfMercy\PhpPdf\Modify\EditRevisionBuilder;
use DragonOfMercy\PhpPdf\Modify\PendingChanges;
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
use DragonOfMercy\PhpPdf\Signature\Signature;
use DragonOfMercy\PhpPdf\Signature\SignatureAppearance;
use DragonOfMercy\PhpPdf\Signature\SignatureFormat;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Signature\Tsa;

/**
 * Opens an existing PDF for modification. Changes are written as an APPENDED
 * incremental revision: the original bytes stay byte-for-byte intact, which
 * preserves any existing digital signatures. When the source is ENCRYPTED, the
 * recovered file key/scheme is reused to re-encrypt the new objects of the
 * appended revision and the source /Encrypt + /ID are forwarded into the
 * revision trailer, so the edited file stays a valid encrypted PDF. Signing an
 * encrypted source is not yet supported.
 *
 * This class is a thin public facade: it accumulates the requested edits and
 * signatures, then delegates the actual revision construction to
 * {@see EditRevisionBuilder} at {@see output()}.
 *
 * Known limitation: appended pages carry their own /MediaBox, /Resources and
 * /Rotate 0 so nothing harmful is inherited from the existing tree; an
 * inherited /CropBox smaller than that box could still apply, which is
 * harmless in v1.
 */
final class PdfEditor
{
    private readonly PdfReader $reader;
    private readonly string $bytes;
    private PendingChanges $pending;

    /**
     * Re-encrypts each new object of the appended revision when the source is
     * encrypted; null for non-encrypted sources (the revision write path is then
     * byte-identical to the unencrypted editor).
     */
    private readonly ?IncrementalObjectEncryptor $encryptor;

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

    private function __construct(string $bytes, ?string $password = null)
    {
        if (!str_starts_with($bytes, '%PDF-')) {
            throw new PdfException('Cannot modify a PDF whose %PDF header is not at byte 0; re-save the file first');
        }
        $this->reader = PdfReader::fromBytes($bytes, $password);
        if ($this->reader->isEncrypted()) {
            // isEncrypted() implies an authenticated security handler.
            $handler = $this->reader->securityHandler();
            if ($handler === null) {
                throw new PdfException('Internal error: encrypted source has no security handler');
            }
            $this->encryptor = new IncrementalObjectEncryptor(
                $handler,
                static function (int $n): string {
                    if ($n < 1) {
                        throw new PdfException('Internal error: unexpected IV byte count: ' . $n);
                    }
                    return random_bytes($n);
                },
                $handler->encryptMetadata(),
            );
        } else {
            $this->encryptor = null;
        }
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

    public static function open(string $path, ?string $password = null): self
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new PdfException("Cannot read PDF file: {$path}");
        }
        return new self($bytes, $password);
    }

    public static function fromBytes(string $bytes, ?string $password = null): self
    {
        return new self($bytes, $password);
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
        $this->fontResolver = CustomFontFamilyRegistrar::register(
            $this->customFontFamilies,
            $alias,
            $regular,
            $bold,
            $italic,
            $boldItalic,
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
                value: FieldValueDecoder::decode($rf, $this->reader),
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
            throw $this->unknownFieldException($name, $allFields, 'fill');
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
     * Queues a flatten: at output() the named fields (or every value-bearing
     * field when $names is null) are frozen into static page content - each
     * widget's appearance is burned into its page and the field's interactivity
     * removed. Signature and PushButton fields are never flattened; naming one
     * throws. Text/Checkbox/Radio/Combobox/Listbox are eligible.
     *
     * @param ?list<string> $names null = all value-bearing fields; a list = the named subset
     */
    public function flattenFields(?array $names = null): self
    {
        if ($names !== null) {
            $terminals = $this->fieldTree()->terminalFields();
            $byName = [];
            foreach ($terminals as $rf) {
                $byName[$rf->name] = $rf;
            }
            foreach ($names as $name) {
                $rf = $byName[$name] ?? null;
                if ($rf === null) {
                    throw $this->unknownFieldException($name, $terminals, 'flatten');
                }
                if ($rf->type === FormFieldType::Signature || $rf->type === FormFieldType::PushButton) {
                    throw new PdfException("Field '{$name}' is a {$rf->type->name} and cannot be flattened");
                }
            }
        }

        $this->pending->flatten = true;
        $this->pending->flattenNames = $names;
        return $this;
    }

    /**
     * Builds the PdfException for an unrecognized field name: an empty-form
     * message when there are no fields, else a Levenshtein-ranked "did you mean".
     *
     * @param list<ResolvedField> $terminals
     */
    private function unknownFieldException(string $name, array $terminals, string $verb): PdfException
    {
        if ($terminals === []) {
            return new PdfException("This PDF has no AcroForm fields to {$verb}");
        }
        $suggestions = [];
        foreach ($terminals as $t) {
            $suggestions[] = [$t->name, levenshtein($name, $t->name)];
        }
        usort($suggestions, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
        $hint = implode(', ', array_column(array_slice($suggestions, 0, 3), 0));
        return new PdfException("Unknown form field '{$name}'. Did you mean: {$hint}?");
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
        $material = LtvMaterialCollector::collect($resolver, $this->signingCertificates, $timestampCertificateChains);
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
     * edits). $field names the signature field: if an empty /FT /Sig field of
     * that name already exists it is reused in place, otherwise a new invisible
     * /FT /Sig field is created on the first page. Pass $appearance for a
     * visible field (a box with a Helvetica caption on a chosen page).
     * Mirrors Document::sign().
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

        if ($this->encryptor !== null && $this->appendedRevisions !== []) {
            throw new PdfException('Signing an encrypted PDF is not yet supported');
        }

        $builder = $this->revisionBuilder();

        if ($this->appendedRevisions === []) {
            return $builder->assembleRevision();
        }

        ['bytes' => $bytes, 'context' => $ctx] = $builder->buildSigningBase();
        $plans = $builder->resolveSignatureFields();
        return (new IncrementalRevisionStacker())->stack($bytes, $ctx, $this->appendedRevisions, $plans);
    }

    public function save(string $path): void
    {
        if (@file_put_contents($path, $this->output()) === false) {
            throw new PdfException("Cannot write PDF file: {$path}");
        }
    }

    /**
     * Constructs the {@see EditRevisionBuilder} for the current accumulated
     * state. The page emitter is built eagerly (cheap, side-effect-free) and is
     * only exercised when there are appended pages.
     */
    private function revisionBuilder(): EditRevisionBuilder
    {
        return new EditRevisionBuilder(
            reader: $this->reader,
            bytes: $this->bytes,
            pending: $this->pending,
            fieldTree: $this->fieldTree(),
            metricsRegistry: $this->metricsRegistry,
            pageEmitter: $this->pageSetEmitter(),
            appendedRevisions: $this->appendedRevisions,
            signatureAppearances: $this->signatureAppearances,
            encryptor: $this->encryptor,
        );
    }

    private function pageSetEmitter(): PageSetEmitter
    {
        return new PageSetEmitter(
            fontRegistry: $this->fontRegistry,
            fontResolver: $this->fontResolver,
            imageRegistry: $this->imageRegistry,
            svgFilterDpi: 300,
            glyphUsage: $this->glyphUsage,
            unit: $this->unit,
            customFontFamilies: $this->customFontFamilies,
        );
    }
}
