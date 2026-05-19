<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

/**
 * The two subsetting artifacts the emitter needs that differ from the
 * original ParsedTtf: the subsetted TTF byte string and the PostScriptName
 * with its 6-letter subset tag prefix (e.g. "ABCDEF+FreeSans"). All other
 * emitted data (/W, ToUnicode, FontDescriptor metrics) come from the original
 * ParsedTtf because GID numbering is preserved.
 *
 * @internal
 */
final readonly class SubsettedFont
{
    public function __construct(
        public string $subsettedBytes,
        public string $prefixedPostScriptName,
    ) {}
}
