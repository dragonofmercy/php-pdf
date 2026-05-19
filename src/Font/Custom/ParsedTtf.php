<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

/**
 * Parsed TTF metrics + original bytes ready for embedding. Em-space integer
 * values are kept as stored in the TTF (per `unitsPerEm`); scaling to PDF's
 * 1000 em-space happens at emission time.
 *
 * @internal
 */
final readonly class ParsedTtf
{
    /**
     * @param array<int, int> $cmap unicode codepoint => glyph index
     * @param array<int, int> $advanceWidthsByGid glyph index => advance width (em units)
     * @param array{int, int, int, int} $bbox [xMin, yMin, xMax, yMax] em units
     */
    public function __construct(
        public string $bytes,
        public string $postScriptName,
        public int $unitsPerEm,
        public int $ascent,
        public int $descent,
        public int $capHeight,
        public int $xHeight,
        public array $bbox,
        public int $italicAngle,
        public int $weight,
        public int $flags,
        public array $cmap,
        public array $advanceWidthsByGid,
        public OutlineFormat $outlineFormat = OutlineFormat::TrueType,
    ) {}
}
