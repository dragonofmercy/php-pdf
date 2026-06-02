<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\PdfA;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;

/**
 * Validates that a document can be emitted as PDF/A. Throws PdfException with
 * the offending value on any violation. PDF/A forbids non-embedded fonts,
 * encryption, and document-level JavaScript; appended incremental revisions
 * (LTV / extra signatures) are out of scope for the PDF/A path in this phase.
 *
 * @internal
 */
final class PdfAConformanceGuard
{
    /**
     * @param list<Font> $standardFonts the Standard-14 fonts registered for emission (never embedded)
     */
    public function verify(
        array $standardFonts,
        bool $hasEncryption,
        bool $hasAppendedRevisions,
        bool $hasDocumentScripts,
        bool $hasAttachmentsAtPart2 = false,
    ): void {
        if ($hasEncryption) {
            throw new PdfException('PDF/A documents cannot be encrypted; remove encryption() before enablePdfA()');
        }
        if ($hasAppendedRevisions) {
            throw new PdfException('PDF/A is not supported together with appended revisions (enableLtv / addSignature / addDocumentTimestamp) in this version');
        }
        if ($hasDocumentScripts) {
            throw new PdfException('PDF/A forbids document-level JavaScript; remove addDocumentScript() before enablePdfA()');
        }
        if ($hasAttachmentsAtPart2) {
            throw new PdfException('PDF/A-2 forbids embedded files; use enablePdfA(PdfALevel::A3B) or A3U to attach files');
        }
        if ($standardFonts !== []) {
            $name = $standardFonts[0]->pdfName();
            throw new PdfException(sprintf(
                "PDF/A requires every font to be embedded, but the non-embeddable standard font '%s' is used. "
                . 'Register an embedded font with registerFontFamily() and use it instead.',
                $name,
            ));
        }
    }
}
