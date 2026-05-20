<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom\Cff;

/**
 * Encoding table (Adobe TN #5176 section 12), name-keyed only. The CFF
 * encoding only matters for Type1 stand-alone use; when embedded as
 * CIDFontType0 + Identity-H it is never consulted, so we keep the raw bytes
 * and write them back unchanged.
 *
 * @internal
 */
final readonly class CffEncoding
{
    public function __construct(public string $rawBytes) {}
}
