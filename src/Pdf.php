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
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfBoolean;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
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

    public function output(): string
    {
        if ($this->pending->isEmpty()) {
            throw new PdfException('No pending changes to write; call a setter or appendPage() first');
        }
        return $this->assembleRevision();
    }

    public function save(string $path): void
    {
        if (@file_put_contents($path, $this->output()) === false) {
            throw new PdfException("Cannot write PDF file: {$path}");
        }
    }

    private function assembleRevision(): string
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
        return (new RevisionWriter())->append(
            reader: $this->reader,
            priorBytes: $this->bytes,
            newObjects: $newObjects,
            trailerEntries: $trailerEntries,
            size: $nextNumber,
        );
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
}
