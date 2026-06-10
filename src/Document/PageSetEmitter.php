<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Document;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\Custom\CustomFontKey;
use DragonOfMercy\PhpPdf\Font\Custom\FontResolver;
use DragonOfMercy\PhpPdf\Font\Custom\GlyphUsage;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Form\AcroFormEmitter;
use DragonOfMercy\PhpPdf\Form\FormField;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\ImageEmbedder;
use DragonOfMercy\PhpPdf\Image\ImageRegistry;
use DragonOfMercy\PhpPdf\Image\SvgMetadata;
use DragonOfMercy\PhpPdf\Import\ImportedPageTemplate;
use DragonOfMercy\PhpPdf\Import\TemplateEmitter;
use DragonOfMercy\PhpPdf\Outline\LinkAnnotation;
use DragonOfMercy\PhpPdf\Outline\LinkAnnotationEmitter;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\Signature\Signature;
use DragonOfMercy\PhpPdf\Signature\SignatureDictionaryEmitter;
use DragonOfMercy\PhpPdf\Svg\SvgFontResolver;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\PdfObjectAllocator;

/**
 * Emits every indirect object a set of pages needs during serialization:
 * page dictionaries, content streams, link annotations, standard-font
 * dictionaries, subsetted custom fonts, image XObjects (with SMasks and SVG
 * Forms), and the /AcroForm block for any form-field widgets the pages
 * declare. All objects share a single numbering driven by the allocator
 * passed to {@see emit()}, in the exact order Document has always used:
 * pages+contents first, then standard fonts, custom fonts, images, and
 * finally the AcroForm objects (numbered after everything else but written
 * between the page objects and the standard-font objects).
 *
 * Scope of the cut: this class owns the pages+fonts+images core (including
 * widget/AcroForm emission, which is interleaved with the page objects and
 * needs the same allocator). Catalog-level concerns stay in Document:
 * outlines, the structure tree, /Names trees, viewer preferences, output
 * intents, attachments, metadata/Info, encryption, and appended revisions.
 * The optional Signature is injected only so the AcroForm block can attach
 * the signature dictionary; pass null when emitting unsigned page sets.
 *
 * @internal
 */
final readonly class PageSetEmitter
{
    /**
     * @param array<string, array{regular: ParsedTtf, bold: ?ParsedTtf, italic: ?ParsedTtf, boldItalic: ?ParsedTtf}> $customFontFamilies registered families, used to resolve subset emissions by CustomFontKey
     * @param array<string, ImportedPageTemplate> $importedTemplates document-wide short name => imported page template
     */
    public function __construct(
        private FontRegistry $fontRegistry,
        private ?FontResolver $fontResolver,
        private ImageRegistry $imageRegistry,
        private int $svgFilterDpi,
        private GlyphUsage $glyphUsage,
        private Unit $unit,
        private array $customFontFamilies,
        private ?Signature $signature = null,
        private array $importedTemplates = [],
    ) {}

    /**
     * Builds:
     *   - page IndirectObjects (with optional /Contents and /Resources entries),
     *   - content-stream IndirectObjects (for pages that drew something),
     *   - font IndirectObjects (one per registered font in the whole doc),
     *   - image and SMask IndirectObjects (for each registered image).
     *
     * All objects share a single numbering driven by $allocator.
     *
     * @param list<Page> $pages
     * @return array{
     *   objects: list<IndirectObject>,
     *   pageRefs: list<PdfReference>,
     *   pageHeightsPt: list<float>,
     *   allWidgets: list<array{field: FormField, widgetRef: PdfReference, pageRef: PdfReference, pageHeightPt: float}>,
     *   acroFormRef: ?PdfReference,
     *   linkAnnotationMap: \SplObjectStorage<LinkAnnotation, int>
     * }
     */
    public function emit(array $pages, PdfObjectAllocator $allocator, PdfReference $pagesRef): array
    {
        $this->preregisterFormFonts($pages);
        $this->preregisterSvgTextFonts();

        $alloc = $this->allocateObjectNumbers($pages, $allocator);
        $pending = $alloc['pending'];
        $pageRefs = $alloc['pageRefs'];
        $pageHeightsPt = $alloc['pageHeightsPt'];
        $linkAnnotationEmitter = $alloc['linkAnnotationEmitter'];
        $fontRefs = $alloc['fontRefs'];
        $customRefs = $alloc['customRefs'];
        $customEmissions = $alloc['customEmissions'];
        $imageRefs = $alloc['imageRefs'];
        $imageEmissions = $alloc['imageEmissions'];

        // imported page templates: emit each used template once, as a Form
        // XObject plus its deep-copied resource subgraph. Numbers are drawn
        // from the same allocator (after images, before per-page annotations).
        ['refs' => $templateRefs, 'objects' => $templateObjects] = $this->emitTemplates($pages, $allocator);

        $pageBuild = (new PageObjectsBuilder(
            allocator: $allocator,
            fontRegistry: $this->fontRegistry,
            fontResolver: $this->fontResolver,
            linkAnnotationEmitter: $linkAnnotationEmitter,
            pagesRef: $pagesRef,
            fontRefs: $fontRefs,
            customRefs: $customRefs,
            imageRefs: $imageRefs,
            templateRefs: $templateRefs,
        ))->build($pending, $pageRefs, $pageHeightsPt);

        $objects = $pageBuild['objects'];
        foreach ($templateObjects as $templateObject) {
            $objects[] = $templateObject;
        }
        /** @var list<array{field: FormField, widgetRef: PdfReference, pageRef: PdfReference, pageHeightPt: float}> $allWidgets */
        $allWidgets = $pageBuild['allWidgets'];
        $linkAnnotationMap = $pageBuild['linkAnnotationMap'];

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

        return [
            'objects' => $objects,
            'pageRefs' => $pageRefs,
            'pageHeightsPt' => $pageHeightsPt,
            'allWidgets' => $allWidgets,
            'acroFormRef' => $acroFormRef,
            'linkAnnotationMap' => $linkAnnotationMap,
        ];
    }

    /**
     * Pre-registers Helvetica if any page has form fields (the /AcroForm dict
     * needs it via /DR /Font /Helv), and any Standard 14 Courier/Times fonts
     * found in FieldAppearance entries (exposed as /Cour and /TiRo). Done
     * before the object-number allocation so these fonts get stable numbers
     * even when no page draws text with them directly.
     *
     * @param list<Page> $pages
     */
    private function preregisterFormFonts(array $pages): void
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
        foreach ($pages as $p) {
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
     * @param list<Page> $pages
     * @return array{
     *   pending: list<array{Page, int, ?int}>,
     *   pageRefs: list<PdfReference>,
     *   pageHeightsPt: list<float>,
     *   linkAnnotationEmitter: ?LinkAnnotationEmitter,
     *   fontRefs: array<string, PdfReference>,
     *   customRefs: array<string, PdfReference>,
     *   customEmissions: list<array{ParsedTtf, CustomFontKey, int, int, int, int, int}>,
     *   imageRefs: array<string, PdfReference>,
     *   imageEmissions: list<array{Image, int}>
     * }
     */
    private function allocateObjectNumbers(array $pages, PdfObjectAllocator $allocator): array
    {
        /** @var list<array{Page, int, ?int}> $pending page + its assigned number + optional content number */
        $pending = [];
        $pageRefs = [];
        /** @var list<float> $pageHeightsPt page heights in points, matched 1:1 with $pageRefs. */
        $pageHeightsPt = [];
        $linkAnnotationEmitter = null;
        foreach ($pages as $page) {
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

    /**
     * Emits every registered template that at least one page uses. Each is
     * emitted exactly once (the document-wide registry already deduplicates by
     * template instance), so the same template placed on many pages shares one
     * Form XObject.
     *
     * @param list<Page> $pages
     * @return array{refs: array<string, PdfReference>, objects: list<IndirectObject>}
     */
    private function emitTemplates(array $pages, PdfObjectAllocator $allocator): array
    {
        $usedTemplateNames = [];
        foreach ($pages as $page) {
            foreach ($page->templatesUsed() as $shortName) {
                $usedTemplateNames[$shortName] = true;
            }
        }
        if ($usedTemplateNames === []) {
            return ['refs' => [], 'objects' => []];
        }

        $refs = [];
        $objects = [];
        $emitter = new TemplateEmitter();
        foreach ($this->importedTemplates as $shortName => $template) {
            if (!isset($usedTemplateNames[$shortName])) {
                continue;
            }
            $emitted = $emitter->emit($template, $allocator);
            $refs[$shortName] = $emitted['xobject']->reference();
            $objects[] = $emitted['xobject'];
            foreach ($emitted['objects'] as $copied) {
                $objects[] = $copied;
            }
        }
        return ['refs' => $refs, 'objects' => $objects];
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
}
