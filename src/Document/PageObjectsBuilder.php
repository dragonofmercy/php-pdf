<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Document;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\CustomFontKey;
use DragonOfMercy\PhpPdf\Font\Custom\FontResolver;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Form\FormField;
use DragonOfMercy\PhpPdf\Outline\LinkAnnotationEmitter;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\TabOrder;
use DragonOfMercy\PhpPdf\Writer\Object\CompressedStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\PdfObjectAllocator;

/**
 * Emits the per-page indirect objects during document serialization.
 *
 * For each pending page entry (page, pageNum, contentNum) this class emits:
 *   1. link-annotation objects (if any), in order,
 *   2. the page dictionary object,
 *   3. the content-stream object (if the page drew anything).
 *
 * The allocator is mutated in place (annotation and widget IDs are consumed via
 * next()), so the SAME PdfObjectAllocator instance must be passed here and then
 * read by the caller for the AcroForm block that follows.
 *
 * @internal
 */
final readonly class PageObjectsBuilder
{
    /**
     * @param array<string, PdfReference> $fontRefs   standard-font short name => reference
     * @param array<string, PdfReference> $customRefs custom-font short name => Type0 reference
     * @param array<string, PdfReference> $imageRefs  image short name => main image reference
     */
    public function __construct(
        private PdfObjectAllocator $allocator,
        private FontRegistry $fontRegistry,
        private ?FontResolver $fontResolver,
        private ?LinkAnnotationEmitter $linkAnnotationEmitter,
        private PdfReference $pagesRef,
        private array $fontRefs,
        private array $customRefs,
        private array $imageRefs,
    ) {}

    /**
     * Builds all per-page objects and returns them together with the widget
     * entries needed for the /AcroForm block.
     *
     * @param list<array{0: Page, 1: int, 2: ?int}> $pending        page + page-object number + optional content-object number
     * @param list<PdfReference>                    $pageRefs        indexed 0-based, one entry per page
     * @param list<float>                           $pageHeightsPt   matched array of page heights in points
     *
     * @return array{objects: list<IndirectObject>, allWidgets: list<array{field: FormField, widgetRef: PdfReference, pageRef: PdfReference, pageHeightPt: float}>}
     */
    public function build(array $pending, array $pageRefs, array $pageHeightsPt): array
    {
        /** @var list<IndirectObject> $objects */
        $objects = [];
        /** @var list<array{field: FormField, widgetRef: PdfReference, pageRef: PdfReference, pageHeightPt: float}> $allWidgets */
        $allWidgets = [];

        foreach ($pending as [$page, $pageNum, $contentNum]) {
            $pageDict = Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Page'))
                ->withEntry(Name::of('Parent'), $this->pagesRef)
                ->withEntry(Name::of('MediaBox'), PdfArray::of(
                    PdfNumber::ofInt(0),
                    PdfNumber::ofInt(0),
                    PdfNumber::ofFloat($page->pageWidth),
                    PdfNumber::ofFloat($page->pageHeight),
                ));

            $resources = $this->buildResources($page);
            $pageDict = $pageDict->withEntry(Name::of('Resources'), $resources);

            $linkAnnotations = $page->getLinkAnnotations();
            $formFields = $page->getFormFields();
            $annotRefs = [];

            if ($linkAnnotations !== [] && $this->linkAnnotationEmitter !== null) {
                $pageContext = sprintf('page object #%d', $pageNum);
                foreach ($linkAnnotations as $annot) {
                    $annotId = $this->allocator->next();
                    $objects[] = $this->linkAnnotationEmitter->emit(
                        $annot,
                        $page->pageHeight,
                        $pageRefs,
                        $pageHeightsPt,
                        $annotId,
                        $pageContext,
                    );
                    $annotRefs[] = PdfReference::to($annotId, 0);
                }
            }

            if ($formFields !== []) {
                // Resolve THIS page's index in $pageRefs (matches $pending entries by pageNum).
                $thisPageRefIndex = null;
                foreach ($pending as $idx => $entry) {
                    if ($entry[1] === $pageNum) {
                        $thisPageRefIndex = $idx;
                        break;
                    }
                }
                if ($thisPageRefIndex === null) {
                    throw new PdfException('Internal: cannot resolve page index for form fields');
                }
                foreach ($formFields as $field) {
                    $widgetId = $this->allocator->next();
                    $widgetRef = PdfReference::to($widgetId, 0);
                    $annotRefs[] = $widgetRef;
                    $allWidgets[] = [
                        'field' => $field,
                        'widgetRef' => $widgetRef,
                        'pageRef' => $pageRefs[$thisPageRefIndex],
                        'pageHeightPt' => $page->pageHeight,
                    ];
                }
            }

            if ($annotRefs !== []) {
                $pageDict = $pageDict->withEntry(Name::of('Annots'), PdfArray::of(...$annotRefs));
            }

            $tabOrder = $page->tabOrder();
            if ($tabOrder !== null) {
                $pageDict = $pageDict->withEntry(Name::of('Tabs'), Name::of(match ($tabOrder) {
                    TabOrder::ROW => 'R',
                    TabOrder::COLUMN => 'C',
                    TabOrder::STRUCTURE => 'S',
                }));
            }

            if ($page->document()?->isTaggingEnabled() === true) {
                $pageDict = $pageDict
                    ->withEntry(Name::of('StructParents'), PdfNumber::ofInt($page->pageIndex()))
                    ->withEntry(Name::of('Tabs'), Name::of('S'));
            }

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

        return ['objects' => $objects, 'allWidgets' => $allWidgets];
    }

    /**
     * Builds the /Resources dictionary for a single page, referencing the
     * pre-allocated font and image refs by short name.
     */
    private function buildResources(Page $page): Dictionary
    {
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
                    $fontDict = $fontDict->withEntry(Name::of($shortName), $this->customRefs[$shortName]);
                } else {
                    $shortName = $this->fontRegistry->shortName($font);
                    $fontDict = $fontDict->withEntry(Name::of($shortName), $this->fontRefs[$shortName]);
                }
            }
            $resources = $resources->withEntry(Name::of('Font'), $fontDict);
        }
        if ($pageImages !== []) {
            $xObjectDict = Dictionary::empty();
            foreach ($pageImages as $imageShort) {
                $xObjectDict = $xObjectDict->withEntry(
                    Name::of($imageShort),
                    $this->imageRefs[$imageShort],
                );
            }
            $resources = $resources->withEntry(Name::of('XObject'), $xObjectDict);
        }

        return $resources;
    }
}
