<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill\Font;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\Custom\Utf8;
use DragonOfMercy\PhpPdf\Font\Custom\Utf8ToCidEncoder;

/**
 * AppearanceFont for an embedded Type0 (Identity-H, CIDFontType2) font reused
 * from the source /DR. CID == GID under Identity-H + Identity CIDToGIDMap, so
 * widths come from the parsed font program and show-text is 2-byte GID hex.
 *
 * @internal
 */
final class CompositeEmbeddedAppearanceFont implements AppearanceFont
{
    public function __construct(
        private readonly ParsedTtf $ttf,
        private readonly string $fieldName,
    ) {}

    public function measureWidth(string $text, float $size): float
    {
        if ($text === '') {
            return 0.0;
        }
        $totalEm = 0;
        foreach (Utf8::codepoints($text) as [$cp, $_]) {
            $totalEm += $this->ttf->advanceWidthsByGid[$this->gidFor($cp)] ?? 0;
        }
        return $totalEm * $size / $this->ttf->unitsPerEm;
    }

    public function encodeShowOperand(string $text): string
    {
        foreach (Utf8::codepoints($text) as [$cp, $_]) {
            $this->gidFor($cp);
        }
        [$bytes] = Utf8ToCidEncoder::encodeWithGids($text, $this->ttf);
        return '<' . strtoupper(bin2hex($bytes)) . '>';
    }

    private function gidFor(int $cp): int
    {
        $gid = $cp >= 0 ? ($this->ttf->cmap[$cp] ?? 0) : 0;
        if ($gid === 0) {
            $where = $cp >= 0 ? sprintf('U+%04X', $cp) : 'an invalid byte sequence';
            throw new PdfException("Field '{$this->fieldName}': character {$where} has no glyph in the embedded font");
        }
        return $gid;
    }
}
