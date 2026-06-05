<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tagging;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Standard PDF logical-structure types used by Phase 1 auto-tagging. The
 * backing value is the PDF structure tag name written to a StructElem's /S.
 */
enum StructureType: string
{
    case Document = 'Document';
    case Part = 'Part';
    case P = 'P';
    case H1 = 'H1';
    case H2 = 'H2';
    case H3 = 'H3';
    case H4 = 'H4';
    case H5 = 'H5';
    case H6 = 'H6';
    case Table = 'Table';
    case TR = 'TR';
    case TH = 'TH';
    case TD = 'TD';
    case L = 'L';
    case LI = 'LI';
    case LBody = 'LBody';
    case Figure = 'Figure';
    case Link = 'Link';
    case Caption = 'Caption';
    case Span = 'Span';

    public static function headingForLevel(int $level): self
    {
        if ($level < 1) {
            throw new PdfException("Heading level must be at least 1, got {$level}");
        }
        return match (min($level, 6)) {
            1 => self::H1,
            2 => self::H2,
            3 => self::H3,
            4 => self::H4,
            5 => self::H5,
            default => self::H6,
        };
    }
}
