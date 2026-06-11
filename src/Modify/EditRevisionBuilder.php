<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Modify;

use DragonOfMercy\PhpPdf\Document\Metadata;
use DragonOfMercy\PhpPdf\Document\MetadataStream;
use DragonOfMercy\PhpPdf\Document\PageSetEmitter;
use DragonOfMercy\PhpPdf\Document\XmpWriter;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Form\Fill\FieldTree;
use DragonOfMercy\PhpPdf\Form\Fill\FieldValueApplier;
use DragonOfMercy\PhpPdf\Form\Fill\FormFieldType;
use DragonOfMercy\PhpPdf\Reader\DictReader;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Signature\AppendedRevision;
use DragonOfMercy\PhpPdf\Signature\AppendedSignature;
use DragonOfMercy\PhpPdf\Signature\Ltv\DssRevision;
use DragonOfMercy\PhpPdf\Signature\ReuseFieldPlan;
use DragonOfMercy\PhpPdf\Signature\RevisionContext;
use DragonOfMercy\PhpPdf\Signature\SignatureAppearance;
use DragonOfMercy\PhpPdf\Signature\SignatureFieldPlan;
use DragonOfMercy\PhpPdf\Signature\VisibleFieldPlan;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfBoolean;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use DragonOfMercy\PhpPdf\Writer\Object\TextString;
use DragonOfMercy\PhpPdf\Writer\PdfObjectAllocator;

/**
 * @internal Builds the appended revision(s) for a {@see \DragonOfMercy\PhpPdf\PdfEditor}:
 * the edit revision (metadata / appended pages / filled fields) and, for the
 * signing path, the signing base bytes + {@see RevisionContext} + per-field
 * {@see SignatureFieldPlan}s that signatures are stacked onto. Extracted from
 * PdfEditor so that class stays a thin public facade; all source queries (page
 * tree, geometry, /ID) live here too.
 */
final class EditRevisionBuilder
{
    /** @var list<PdfReference>|null Memoized flattened source page-reference list (the source tree never changes). */
    private ?array $pageReferences = null;

    /**
     * @param list<AppendedRevision|DssRevision> $appendedRevisions queued signatures / timestamps / DSS to stack
     * @param array<string, SignatureAppearance> $signatureAppearances visible-signature boxes keyed by field name
     */
    public function __construct(
        private readonly PdfReader $reader,
        private readonly string $bytes,
        private readonly PendingChanges $pending,
        private readonly FieldTree $fieldTree,
        private readonly MetricsRegistry $metricsRegistry,
        private readonly PageSetEmitter $pageEmitter,
        private readonly array $appendedRevisions,
        private readonly array $signatureAppearances,
    ) {
    }

    public function assembleRevision(): string
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
     * Produces the bytes a signature revision is stacked onto, plus the
     * RevisionContext describing the catalog / AcroForm / first page / next
     * object number / document id at that point. When there are pending edits
     * they are written as the first incremental revision; otherwise the source
     * bytes are returned unchanged.
     *
     * @return array{bytes: string, context: RevisionContext}
     */
    public function buildSigningBase(): array
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
    public function resolveSignatureFields(): array
    {
        $plans = [];
        $terminals = [];
        foreach ($this->fieldTree->terminalFields() as $rf) {
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
                $plans[$name] = new VisibleFieldPlan($page, $rect, $appearance);
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
            $plans[$name] = new ReuseFieldPlan(IndirectObject::of($rf->objectNumber, 0, $rf->dict));
        }
        return $plans;
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
        $build = $this->pageEmitter->emit($this->pending->pages, $allocator, $pagesRootRef);
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
        foreach ($this->fieldTree->terminalFields() as $rf) {
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
     * Memoized flattened list of leaf /Type /Page references (the source tree
     * never changes after open).
     *
     * @return list<PdfReference>
     */
    private function pageReferences(): array
    {
        return $this->pageReferences ??= $this->buildPageReferences();
    }

    /**
     * Flattens the source page tree to the ordered list of leaf /Type /Page
     * references.
     *
     * @return list<PdfReference>
     */
    private function buildPageReferences(): array
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
