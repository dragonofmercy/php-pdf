<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use DragonOfMercy\PhpPdf\Svg\SvgClipped;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgMasked;
use DragonOfMercy\PhpPdf\Svg\SvgNode;
use DragonOfMercy\PhpPdf\Svg\SvgText;
use DragonOfMercy\PhpPdf\Svg\ViewBox;

final readonly class SvgMetadata
{
    /**
     * @param list<\DragonOfMercy\PhpPdf\Image> $embeddedImages distinct rasters,
     *        deduped by contentHash; SvgImage::$imageIndex indexes this list.
     */
    public function __construct(
        public ViewBox $viewBox,
        public PreserveAspectRatio $aspectRatio,
        public SvgGroup $root,
        public array $embeddedImages = [],
    ) {}

    /**
     * Every standard Font referenced by a text span anywhere in the tree.
     * Duplicates are harmless: the FontRegistry dedupes by pdfName. Used by the
     * Document pre-pass to allocate font objects before object numbering.
     *
     * @return list<Font>
     */
    public function textFonts(): array
    {
        $fonts = [];
        $this->walk($this->root, $fonts);
        return $fonts;
    }

    /**
     * @param list<Font> $fonts accumulator
     */
    private function walk(SvgNode $node, array &$fonts): void
    {
        if ($node instanceof SvgText) {
            foreach ($node->spans as $span) {
                $fonts[] = $span->font;
            }
            return;
        }
        if ($node instanceof SvgGroup) {
            foreach ($node->children as $child) {
                $this->walk($child, $fonts);
            }
            return;
        }
        if ($node instanceof SvgClipped) {
            $this->walk($node->child, $fonts);
            return;
        }
        if ($node instanceof SvgMasked) {
            // <text> may live both inside the masked element and inside the
            // mask definition itself; descend into both so the pre-pass sees
            // every standard font referenced anywhere in the tree.
            $this->walk($node->child, $fonts);
            foreach ($node->mask->nodes as $maskNode) {
                $this->walk($maskNode, $fonts);
            }
        }
    }
}
