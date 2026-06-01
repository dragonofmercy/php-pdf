<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use DragonOfMercy\PhpPdf\Svg\SvgClipped;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgMasked;
use DragonOfMercy\PhpPdf\Svg\SvgNode;
use DragonOfMercy\PhpPdf\Svg\SvgText;
use DragonOfMercy\PhpPdf\Svg\SvgTextPath;
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
     * Every text font spec (raw font-family list plus weight/style) referenced
     * by a text span anywhere in the tree. Duplicates are harmless: the
     * FontRegistry dedupes by pdfName. Used by the Document pre-pass to allocate
     * font objects before object numbering. Resolution to a Font is deferred to
     * the caller so registered custom families can be honored.
     *
     * @return list<array{family: string, bold: bool, italic: bool}>
     */
    public function textFontSpecs(): array
    {
        $specs = [];
        $this->walk($this->root, $specs);
        return $specs;
    }

    /**
     * @param list<array{family: string, bold: bool, italic: bool}> $specs accumulator
     */
    private function walk(SvgNode $node, array &$specs): void
    {
        if ($node instanceof SvgText) {
            foreach ($node->spans as $span) {
                $specs[] = ['family' => $span->fontFamily, 'bold' => $span->bold, 'italic' => $span->italic];
            }
            return;
        }
        if ($node instanceof SvgTextPath) {
            foreach ($node->spans as $span) {
                $specs[] = ['family' => $span->fontFamily, 'bold' => $span->bold, 'italic' => $span->italic];
            }
            return;
        }
        if ($node instanceof SvgGroup) {
            foreach ($node->children as $child) {
                $this->walk($child, $specs);
            }
            return;
        }
        if ($node instanceof SvgClipped) {
            $this->walk($node->child, $specs);
            return;
        }
        if ($node instanceof SvgMasked) {
            // <text> may live both inside the masked element and inside the
            // mask definition itself; descend into both so the pre-pass sees
            // every font referenced anywhere in the tree.
            $this->walk($node->child, $specs);
            foreach ($node->mask->nodes as $maskNode) {
                $this->walk($maskNode, $specs);
            }
        }
    }
}
