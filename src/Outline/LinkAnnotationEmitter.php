<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Outline;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;

/**
 * Builds the single `IndirectObject` representing one `/Annot /Link`
 * annotation (PDF 1.7 section 12.5.6.5). Stateless modulo the document
 * `Unit`. The caller (Document::buildPagesFontsImages) is responsible for
 * collecting the produced IndirectObject and adding its reference to the
 * page's `/Annots` array.
 *
 * @internal
 */
final readonly class LinkAnnotationEmitter
{
    public function __construct(private Unit $unit) {}

    /**
     * @param list<PdfReference> $pageRefs       indexed by 0-based pageIndex (for GoTo resolution)
     * @param list<float>        $pageHeightsPt  matched array of page heights (for Y-flip on XYZ/FitH)
     */
    public function emit(
        LinkAnnotation $annot,
        float $pageHeightPt,
        array $pageRefs,
        array $pageHeightsPt,
        int $id,
        string $context,
    ): IndirectObject {
        $xPt = $this->unit->toPoints($annot->x);
        $yPt = $this->unit->toPoints($annot->y);
        $wPt = $this->unit->toPoints($annot->width);
        $hPt = $this->unit->toPoints($annot->height);

        // Y-flip: top-down user coords -> bottom-up PDF coords.
        // Rect order is [llx lly urx ury].
        $llx = $xPt;
        $lly = $pageHeightPt - ($yPt + $hPt);
        $urx = $xPt + $wPt;
        $ury = $pageHeightPt - $yPt;

        $rect = PdfArray::of(
            PdfNumber::ofFloat($llx),
            PdfNumber::ofFloat($lly),
            PdfNumber::ofFloat($urx),
            PdfNumber::ofFloat($ury),
        );

        $action = $this->buildAction($annot->link, $pageRefs, $pageHeightsPt, $context);

        $dict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Annot'))
            ->withEntry(Name::of('Subtype'), Name::of('Link'))
            ->withEntry(Name::of('Rect'), $rect)
            ->withEntry(Name::of('Border'), PdfArray::of(
                PdfNumber::ofInt(0),
                PdfNumber::ofInt(0),
                PdfNumber::ofInt(0),
            ))
            ->withEntry(Name::of('A'), $action);

        return IndirectObject::of($id, 0, $dict);
    }

    /**
     * @param list<PdfReference> $pageRefs
     * @param list<float>        $pageHeightsPt
     */
    private function buildAction(Link $link, array $pageRefs, array $pageHeightsPt, string $context): Dictionary
    {
        if ($link->url !== null) {
            return Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Action'))
                ->withEntry(Name::of('S'), Name::of('URI'))
                ->withEntry(Name::of('URI'), PdfString::of($link->url));
        }

        if ($link->destination === null) {
            throw new PdfException("Link payload has neither URL nor destination for {$context}");
        }

        return Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Action'))
            ->withEntry(Name::of('S'), Name::of('GoTo'))
            ->withEntry(Name::of('D'), $link->destination->toPdfArray($pageRefs, $pageHeightsPt, $this->unit, $context));
    }
}
