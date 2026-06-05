<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tagging;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;

/**
 * Fail-fast PDF/UA-1 conformance checks, mirroring PdfAConformanceGuard. Runs
 * before serialization so authors get an actionable error rather than a
 * silently non-conformant file.
 *
 * @internal
 */
final class TaggingConformanceGuard
{
    /**
     * @param list<Font> $standardFonts the non-embeddable Standard-14 fonts in use
     */
    public function verify(
        array $standardFonts,
        ?string $title,
        StructureTree $tree,
        bool $hasLinkAnnotations,
    ): void {
        if ($standardFonts !== []) {
            $name = $standardFonts[0]->pdfName();
            throw new PdfException(sprintf(
                "PDF/UA requires every font to be embedded, but the non-embeddable standard font '%s' is used. "
                . 'Register an embedded font with registerFontFamily() and use it instead.',
                $name,
            ));
        }

        if ($title === null || $title === '') {
            throw new PdfException(
                "PDF/UA requires a document title; set it with \$document->metadata()->title('...').",
            );
        }

        $this->verifyFiguresAndHeadings($tree->root());

        if ($hasLinkAnnotations) {
            throw new PdfException(
                'PDF/UA-conformant link tagging is not yet supported (planned for Phase 2b); '
                . 'remove link annotations or use enableTagging() instead of enablePdfUA().',
            );
        }
    }

    private function verifyFiguresAndHeadings(StructElem $root): void
    {
        $highestHeading = 0;
        $walk = function (StructElem $elem) use (&$walk, &$highestHeading): void {
            if ($elem->type() === StructureType::Figure && $elem->alt() === null) {
                throw new PdfException(
                    "PDF/UA requires alternate text on every figure; pass image(alt: '...') "
                    . 'or mark it decorative with image(decorative: true).',
                );
            }
            $level = $elem->type()->headingLevel();
            if ($level > 0) {
                if ($level > $highestHeading + 1) {
                    throw new PdfException(sprintf(
                        'PDF/UA requires headings not to skip levels; found H%d with no preceding H%d.',
                        $level,
                        $level - 1,
                    ));
                }
                $highestHeading = max($highestHeading, $level);
            }
            foreach ($elem->children() as $child) {
                if ($child instanceof StructElem) {
                    $walk($child);
                }
            }
        };
        $walk($root);
    }
}
