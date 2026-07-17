<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font;

use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Page\ContentStream;
use DragonOfMercy\PhpPdf\Page\Operators;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;

/**
 * FontEngine for the 12 standard PDF fonts. Backed by FontMetrics; emits
 * WinAnsi-encoded show-text operators byte-identical to the pre-engine path.
 *
 * @internal
 */
final readonly class StandardFontEngine implements FontEngine
{
    public function __construct(
        private Font $font,
        private FontMetrics $metrics,
    ) {}

    public function font(): Font
    {
        return $this->font;
    }

    public function measure(string $text, float $size): float
    {
        if ($text === '') {
            return 0.0;
        }
        return $this->metrics->stringWidth(WinAnsiEncoder::encode($text), $size);
    }

    public function encodeShowText(string $text): string
    {
        return Operators::showText(WinAnsiEncoder::encode($text));
    }

    public function emitShowText(ContentStream $stream, string $text): void
    {
        $stream->append($this->encodeShowText($text));
    }

    public function emitShowTextNextLine(ContentStream $stream, string $text): void
    {
        $stream->append(Operators::showTextNextLine(WinAnsiEncoder::encode($text)));
    }

    public function splitForceBreak(string $token, float $innerW, float $size): array
    {
        $chunks = [];
        $widths = [];
        $current = '';
        $currentWidth = 0.0;

        // Iterate by UTF-8 codepoint so accumulated chunks stay valid UTF-8 (the
        // text pipeline is UTF-8 end to end); measure each character via WinAnsi,
        // exactly like measure(), so force-broken widths match untouched text.
        foreach (mb_str_split($token, 1, 'UTF-8') as $char) {
            $charWidth = $this->measure($char, $size);
            if ($currentWidth + $charWidth > $innerW + 0.0001 && $current !== '') {
                $chunks[] = $current;
                $widths[] = $currentWidth;
                $current = $char;
                $currentWidth = $charWidth;
            } else {
                $current .= $char;
                $currentWidth += $charWidth;
            }
        }
        $chunks[] = $current;
        $widths[] = $currentWidth;

        return [$chunks, $widths];
    }

    public function ascentAt(float $size): float
    {
        return $this->metrics->ascentAt($size);
    }

    public function descentAt(float $size): float
    {
        return $this->metrics->descentAt($size);
    }

    public function capHeightAt(float $size): float
    {
        return $this->metrics->capHeightAt($size);
    }

    public function xHeightAt(float $size): float
    {
        return $this->metrics->xHeight * $size / 1000.0;
    }

    public function registerOn(FontRegistry $registry): string
    {
        return $registry->shortName($this->font);
    }

    public function usageKey(): string
    {
        return $this->font->pdfName();
    }

    public function emitJustifiedLine(
        ContentStream $stream,
        array $segments,
        float $extraPerGapPt,
        float $size,
    ): void {
        $adj = $size === 0.0 ? 0.0 : -$extraPerGapPt / $size * 1000.0;
        $elements = array_map(
            static fn (string $segment): string => PdfString::of(WinAnsiEncoder::encode($segment))->toBytes(),
            $segments,
        );
        $stream->append(Operators::showTextArray(implode(PdfNumber::ofFloat($adj)->toBytes(), $elements)));
    }
}
