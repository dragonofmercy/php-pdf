<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;

/**
 * One page of an existing PDF with its INHERITED attributes resolved
 * (PDF 1.7 7.7.3.4): /MediaBox, /CropBox, /Rotate, /Resources. Boxes are
 * corner-normalized [llx, lly, urx, ury] in points; rotate is 0/90/180/270.
 */
final readonly class ReadPage
{
    /**
     * @param list<float> $mediaBox
     * @param ?list<float> $cropBox
     * @param list<PdfReference> $contents
     * @param ?int $objectNumber source object number of the leaf page, null for an inlined page dict
     */
    public function __construct(
        public Dictionary $dict,
        public array $mediaBox,
        public ?array $cropBox,
        public int $rotate,
        public ?Dictionary $resources,
        public array $contents,
        public ?int $objectNumber = null,
    ) {}

    /**
     * The effective page box for display/import: CropBox when present, else MediaBox.
     *
     * @return list<float>
     */
    public function box(): array
    {
        return $this->cropBox ?? $this->mediaBox;
    }
}
