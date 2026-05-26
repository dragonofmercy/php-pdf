<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

/**
 * Submission data format for a SubmitForm button action. Maps to the PDF
 * SubmitForm /Flags bits (PDF 32000-1:2008 Table 237): FDF is the default
 * (Flags 0), HTML sets ExportFormat, XFDF sets the XFDF bit, PDF sets SubmitPDF.
 */
enum SubmitFormat
{
    case FDF;
    case HTML;
    case XFDF;
    case PDF;
}
