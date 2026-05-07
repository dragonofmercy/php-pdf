<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

/**
 * Which side panel (if any) the viewer should reveal on opening, or whether
 * to launch in full screen (PDF 1.7 §7.7.2, /PageMode).
 *
 * USE_OC and USE_ATTACHMENTS are only meaningful when the document actually
 * has optional content groups or file attachments respectively. FULL_SCREEN
 * is a hint: many viewers (notably browser viewers) ignore or downgrade it.
 *
 * Defaults to UseNone when not set on the catalog.
 */
enum PageMode: string
{
    case USE_NONE        = 'UseNone';
    case USE_OUTLINES    = 'UseOutlines';
    case USE_THUMBS      = 'UseThumbs';
    case FULL_SCREEN     = 'FullScreen';
    case USE_OC          = 'UseOC';
    case USE_ATTACHMENTS = 'UseAttachments';
}
