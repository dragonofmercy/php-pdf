<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\PdfA;

/**
 * The relationship an associated (embedded) file has to the PDF, per ISO
 * 32000-2 / PDF/A-3 (the /AFRelationship key). Data is the Factur-X default.
 */
enum AFRelationship
{
    case Source;
    case Data;
    case Alternative;
    case Supplement;
    case Unspecified;

    public function pdfName(): string
    {
        return match ($this) {
            self::Source => 'Source',
            self::Data => 'Data',
            self::Alternative => 'Alternative',
            self::Supplement => 'Supplement',
            self::Unspecified => 'Unspecified',
        };
    }
}
