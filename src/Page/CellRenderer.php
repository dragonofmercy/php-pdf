<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Page;

use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\BorderStyle;
use DragonOfMercy\PhpPdf\CellPadding;
use DragonOfMercy\PhpPdf\CellResult;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Fit;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\Custom\Utf8;
use DragonOfMercy\PhpPdf\Font\Custom\Utf8ToCidEncoder;
use DragonOfMercy\PhpPdf\Font\FontMetrics;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Font\WinAnsiEncoder;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\VerticalAlign;

/**
 * Encapsulates the cell rendering pipeline (wrap/fit/layout/emit). Owned
 * indirectly by Page::cell() - instantiated per call.
 *
 * @internal
 */
final class CellRenderer
{
    public function __construct(
        private readonly ContentStream $stream,
        private readonly MetricsRegistry $metrics,
    ) {}

    /**
     * Renders the cell into the bound ContentStream and returns geometry.
     * The fontShortName parameter is the registry-assigned `/F<n>` value
     * already minus the leading slash (e.g. `'F1'`).
     *
     * When `$customTtf` is non-null, layout and emission use the parsed TTF
     * (cmap-driven widths, hex Tj). When null, the original WinAnsi path is
     * preserved byte-identically for the 12 standard fonts.
     */
    public function render(
        Font $font,
        float $size,
        ?float $customLeading,
        float $x,
        float $y,
        float $w,
        ?float $h,
        string $text,
        ?Border $border,
        ?Color $fill,
        ?Color $textColor,
        TextAlign $align,
        VerticalAlign $verticalAlign,
        Fit $fit,
        CellPadding $padding,
        string $fontShortName,
        ?ParsedTtf $customTtf = null,
    ): CellResult {
        $innerW = max(0.0, $w - $padding->left - $padding->right);
        $metrics = $customTtf === null ? $this->metrics->metricsFor($font) : null;

        // ---- Phase 1: layout ----
        $lines = [];
        $widths = [];
        $brokenWords = 0;
        $textOverflow = false;
        $effectiveSize = $size;
        $effectiveLeading = $customLeading ?? ($size * 1.2);
        $scales = null;

        if ($text === '') {
            $lineCount = 0;
            $textHeight = 0.0;
        } else {
            if ($customTtf !== null) {
                switch ($fit) {
                    case Fit::NONE:
                        $wrapC = $this->wrapTextCustom($text, $innerW, $customTtf, $size);
                        $lines = $wrapC->lines;
                        $widths = $wrapC->widths;
                        $brokenWords = $wrapC->brokenWords;
                        break;

                    case Fit::CONDENSE:
                        $condC = $this->condenseTextCustom($text, $innerW, $customTtf, $size);
                        $lines = $condC->lines;
                        $scales = $condC->scales;
                        $widths = [];
                        foreach ($lines as $i => $line) {
                            $paraWidth = self::stringWidthCustom($line, $customTtf, $size);
                            $widths[] = $paraWidth * $scales[$i] / 100.0;
                        }
                        break;

                    case Fit::SHRINK:
                        $shrC = $this->shrinkTextCustom($text, $innerW, $customTtf, $size, $customLeading);
                        $lines = $shrC->lines;
                        $widths = $shrC->widths;
                        $effectiveSize = $shrC->effectiveSize;
                        $effectiveLeading = $shrC->effectiveLeading;
                        $textOverflow = $shrC->textOverflow;
                        break;
                }
            } else {
                /** @var FontMetrics $metrics */
                switch ($fit) {
                    case Fit::NONE:
                        $wrap = $this->wrapText($text, $innerW, $font, $size);
                        $lines = $wrap->lines;
                        $widths = $wrap->widths;
                        $brokenWords = $wrap->brokenWords;
                        break;

                    case Fit::CONDENSE:
                        $cond = $this->condenseText($text, $innerW, $font, $size);
                        $lines = $cond->lines;
                        $scales = $cond->scales;
                        $widths = [];
                        foreach ($lines as $i => $line) {
                            $paraWidth = $metrics->stringWidth($line, $size);
                            $widths[] = $paraWidth * $scales[$i] / 100.0;
                        }
                        break;

                    case Fit::SHRINK:
                        $shr = $this->shrinkText($text, $innerW, $font, $size, $customLeading);
                        $lines = $shr->lines;
                        $widths = $shr->widths;
                        $effectiveSize = $shr->effectiveSize;
                        $effectiveLeading = $shr->effectiveLeading;
                        $textOverflow = $shr->textOverflow;
                        break;
                }
            }
            $lineCount = count($lines);

            $descentAbs = $customTtf !== null
                ? abs($customTtf->descent * $effectiveSize / $customTtf->unitsPerEm)
                : abs(($metrics ?? throw new \LogicException('metrics null'))->descentAt($effectiveSize));
            // Vertical extent the text actually needs: em-square above the
            // first baseline + descent below the last + leading between lines.
            $textHeight = $effectiveSize + $descentAbs + ($lineCount - 1) * $effectiveLeading;
        }

        $cellHeight = max($h ?? 0.0, $textHeight + $padding->top + $padding->bottom);

        // ---- Phase 2: emit ----
        $this->stream->append(Operators::saveState());

        if ($fill !== null) {
            $this->stream->append(Operators::saveState());
            $this->stream->append($fill->toPdfOperator(stroke: false));
            $this->stream->append(Operators::rectangle($x, $y, $w, $cellHeight));
            $this->stream->append(Operators::fill());
            $this->stream->append(Operators::restoreState());
        }

        if ($border !== null && !$border->isEmpty()) {
            $this->emitBorders($border, $x, $y, $w, $cellHeight);
        }

        if ($text !== '') {
            if ($customTtf !== null) {
                $this->emitTextCustom(
                    customTtf: $customTtf,
                    lines: $lines,
                    widths: $widths,
                    scales: $scales,
                    effectiveSize: $effectiveSize,
                    effectiveLeading: $effectiveLeading,
                    cellX: $x,
                    cellY: $y,
                    cellW: $w,
                    cellH: $cellHeight,
                    padding: $padding,
                    align: $align,
                    verticalAlign: $verticalAlign,
                    textColor: $textColor,
                    fontShortName: $fontShortName,
                );
            } else {
                /** @var FontMetrics $metrics */
                $this->emitText(
                    metrics: $metrics,
                    lines: $lines,
                    widths: $widths,
                    scales: $scales,
                    effectiveSize: $effectiveSize,
                    effectiveLeading: $effectiveLeading,
                    cellX: $x,
                    cellY: $y,
                    cellW: $w,
                    cellH: $cellHeight,
                    padding: $padding,
                    align: $align,
                    verticalAlign: $verticalAlign,
                    textColor: $textColor,
                    fontShortName: $fontShortName,
                );
            }
        }

        $this->stream->append(Operators::restoreState());

        return new CellResult(
            x: $x + $w,
            y: $y + $cellHeight,
            height: $cellHeight,
            lineCount: $lineCount,
            brokenWords: $brokenWords,
            textOverflow: $textOverflow,
        );
    }

    private function emitBorders(Border $border, float $x, float $y, float $w, float $h): void
    {
        $sides = [
            ['active' => $border->top,    'x1' => $x,       'y1' => $y,      'x2' => $x + $w, 'y2' => $y],
            ['active' => $border->right,  'x1' => $x + $w,  'y1' => $y,      'x2' => $x + $w, 'y2' => $y + $h],
            ['active' => $border->bottom, 'x1' => $x,       'y1' => $y + $h, 'x2' => $x + $w, 'y2' => $y + $h],
            ['active' => $border->left,   'x1' => $x,       'y1' => $y,      'x2' => $x,      'y2' => $y + $h],
        ];

        $dashPattern = match ($border->style) {
            BorderStyle::SOLID => [],
            BorderStyle::DASHED => [3.0, 3.0],
            BorderStyle::DOTTED => [$border->width, 2.0 * $border->width],
        };

        foreach ($sides as $s) {
            if (!$s['active']) {
                continue;
            }
            $this->stream->append(Operators::saveState());
            $this->stream->append($border->color->toPdfOperator(stroke: true));
            $this->stream->append(Operators::setLineWidth($border->width));
            $this->stream->append(Operators::setDashPattern($dashPattern, 0.0));
            $this->stream->append(Operators::moveTo($s['x1'], $s['y1']));
            $this->stream->append(Operators::lineTo($s['x2'], $s['y2']));
            $this->stream->append(Operators::stroke());
            $this->stream->append(Operators::restoreState());
        }
    }

    /**
     * @param list<string> $lines
     * @param list<float> $widths
     * @param list<float>|null $scales per-line Tz (Fit::CONDENSE), null otherwise
     */
    private function emitText(
        FontMetrics $metrics,
        array $lines,
        array $widths,
        ?array $scales,
        float $effectiveSize,
        float $effectiveLeading,
        float $cellX,
        float $cellY,
        float $cellW,
        float $cellH,
        CellPadding $padding,
        TextAlign $align,
        VerticalAlign $verticalAlign,
        ?Color $textColor,
        string $fontShortName,
    ): void {
        $lineCount = count($lines);
        $capHeight = $metrics->capHeightAt($effectiveSize);
        // Bbox-safe vertical space: caps cover capHeight, but ascenders and
        // diacritics can reach up to the em-square (~ effectiveSize), and
        // descenders go down by |descent|. TOP/BOTTOM use these so that
        // padding=0 still keeps every glyph strictly inside the cell;
        // MIDDLE keeps cap-height centring (visible-mass alignment) since
        // its caller usually has comfortable padding above and below.
        $emAbove = $effectiveSize;
        $descentAbs = abs($metrics->descentAt($effectiveSize));
        $capBlockHeight = $capHeight + ($lineCount - 1) * $effectiveLeading;

        $firstBaseline = match ($verticalAlign) {
            VerticalAlign::TOP    => $cellY + $padding->top + $emAbove,
            VerticalAlign::MIDDLE => $cellY + ($cellH - $capBlockHeight) / 2.0 + $capHeight,
            VerticalAlign::BOTTOM => $cellY + $cellH - $padding->bottom - $descentAbs
                - ($lineCount - 1) * $effectiveLeading,
        };

        if ($textColor !== null) {
            $this->stream->append($textColor->toPdfOperator(stroke: false));
        }

        $this->stream->append(Operators::beginText());
        $this->stream->append(Operators::setFontAndSize($fontShortName, $effectiveSize));
        $this->stream->append(Operators::setTextLeading($effectiveLeading));

        foreach ($lines as $i => $line) {
            $lineWidth = $widths[$i];
            $lineX = match ($align) {
                TextAlign::LEFT   => $cellX + $padding->left,
                TextAlign::CENTER => $cellX + ($cellW - $lineWidth) / 2.0,
                TextAlign::RIGHT  => $cellX + $cellW - $padding->right - $lineWidth,
            };
            $lineBaseline = $firstBaseline + $i * $effectiveLeading;
            // Counter-flip Y to compensate the page-level Y-down CTM.
            $this->stream->append(Operators::textMatrix(1, 0, 0, -1, $lineX, $lineBaseline));
            if ($scales !== null) {
                $this->stream->append(Operators::setHorizontalScaling($scales[$i]));
            }
            $this->stream->append(Operators::showText($line));
        }

        $this->stream->append(Operators::endText());
    }

    /**
     * Wraps raw UTF-8 text to fit within `$innerW` points using the supplied
     * font and size. Words longer than `$innerW` are force-broken at byte
     * boundaries; the count is tracked in `WrapResult::$brokenWords`.
     *
     * Each returned line is already WinAnsi-encoded.
     */
    public function wrapText(string $rawText, float $innerW, Font $font, float $size): WrapResult
    {
        $metrics = $this->metrics->metricsFor($font);

        // Split on newlines BEFORE encoding: WinAnsiEncoder converts \n to '?'
        // because 0x0A is a control character outside the printable range.
        $paragraphs = explode("\n", $rawText);

        $lines = [];
        $widths = [];
        $brokenWords = 0;

        foreach ($paragraphs as $paragraph) {
            // Encode each paragraph individually (no newlines inside)
            $encoded = WinAnsiEncoder::encode($paragraph);

            if ($encoded === '') {
                $lines[] = '';
                $widths[] = 0.0;
                continue;
            }

            // Tokenize preserving runs of whitespace so re-assembled lines
            // include intra-line spaces verbatim.
            $tokens = preg_split('/(\s+)/', $encoded, -1, PREG_SPLIT_DELIM_CAPTURE);
            $tokens = $tokens === false ? [$encoded] : array_values(array_filter(
                $tokens,
                static fn(string $t): bool => $t !== '',
            ));

            $currentLine = '';
            $currentWidth = 0.0;

            foreach ($tokens as $token) {
                $tokenWidth = $metrics->stringWidth($token, $size);
                $isSpace = ctype_space($token);

                if ($currentWidth + $tokenWidth <= $innerW + 0.0001) {
                    $currentLine .= $token;
                    $currentWidth += $tokenWidth;
                    continue;
                }

                if ($isSpace) {
                    // Space overflows: flush current line, discard the space
                    $lines[] = $currentLine;
                    $widths[] = $currentWidth;
                    $currentLine = '';
                    $currentWidth = 0.0;
                    continue;
                }

                // Word overflows: flush current line first if non-empty
                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                    $widths[] = $currentWidth;
                    $currentLine = '';
                    $currentWidth = 0.0;
                }

                // If the word itself is wider than innerW, force-break it
                if ($tokenWidth > $innerW) {
                    $brokenWords++;
                    [$chunks, $chunkWidths] = $this->forceBreakWord($token, $innerW, $metrics, $size);
                    $lastIndex = count($chunks) - 1;
                    for ($i = 0; $i < $lastIndex; $i++) {
                        $lines[] = $chunks[$i];
                        $widths[] = $chunkWidths[$i];
                    }
                    $currentLine = $chunks[$lastIndex];
                    $currentWidth = $chunkWidths[$lastIndex];
                } else {
                    $currentLine = $token;
                    $currentWidth = $tokenWidth;
                }
            }

            $lines[] = $currentLine;
            $widths[] = $currentWidth;
        }

        return new WrapResult(
            lines: $lines,
            widths: $widths,
            brokenWords: $brokenWords,
            textOverflow: false,
        );
    }

    /**
     * Layout for `Fit::CONDENSE`: each `\n`-separated paragraph stays on a
     * single line with a Tz scale percentage so it fits within `$innerW`.
     */
    public function condenseText(string $rawText, float $innerW, Font $font, float $size): CondenseResult
    {
        $metrics = $this->metrics->metricsFor($font);
        // Split BEFORE encoding (WinAnsiEncoder maps \n to '?').
        $paragraphs = explode("\n", $rawText);

        $lines = [];
        $scales = [];

        foreach ($paragraphs as $paragraph) {
            $encoded = WinAnsiEncoder::encode($paragraph);
            $paraWidth = $metrics->stringWidth($encoded, $size);
            if ($paraWidth <= 0.0 + 0.0001 || $paraWidth <= $innerW + 0.0001) {
                $scale = 100.0;
            } else {
                $scale = ($innerW / $paraWidth) * 100.0;
            }
            $lines[] = $encoded;
            $scales[] = $scale;
        }

        return new CondenseResult(lines: $lines, scales: $scales);
    }

    /**
     * Layout for `Fit::SHRINK`: a single proportional ratio is applied so the
     * longest paragraph fits in `$innerW`. Below `originalSize/100` the
     * effectiveSize is clamped and `textOverflow` is set true.
     */
    public function shrinkText(
        string $rawText,
        float $innerW,
        Font $font,
        float $originalSize,
        ?float $customLeading = null,
    ): ShrinkResult {
        $metrics = $this->metrics->metricsFor($font);
        // Split BEFORE encoding.
        $paragraphs = array_map(
            static fn (string $p): string => WinAnsiEncoder::encode($p),
            explode("\n", $rawText),
        );

        $maxWidth = 0.0;
        foreach ($paragraphs as $paragraph) {
            $w = $metrics->stringWidth($paragraph, $originalSize);
            if ($w > $maxWidth) {
                $maxWidth = $w;
            }
        }

        if ($maxWidth <= 0.0 + 0.0001 || $maxWidth <= $innerW + 0.0001) {
            $effectiveSize = $originalSize;
            $textOverflow = false;
        } else {
            $ratio = $innerW / $maxWidth;
            $effectiveSize = $originalSize * $ratio;
            $minSize = $originalSize / 100.0;
            if ($effectiveSize < $minSize) {
                $effectiveSize = $minSize;
                $textOverflow = true;
            } else {
                $textOverflow = false;
            }
        }

        $effectiveLeading = $customLeading ?? ($effectiveSize * 1.2);

        $widths = [];
        foreach ($paragraphs as $paragraph) {
            $widths[] = $metrics->stringWidth($paragraph, $effectiveSize);
        }

        return new ShrinkResult(
            lines: $paragraphs,
            widths: $widths,
            effectiveSize: $effectiveSize,
            effectiveLeading: $effectiveLeading,
            textOverflow: $textOverflow,
        );
    }

    /**
     * Force-breaks a word that exceeds innerW into chunks that each fit.
     *
     * @return array{0: list<string>, 1: list<float>}
     */
    private function forceBreakWord(string $token, float $innerW, FontMetrics $metrics, float $size): array
    {
        $chunks = [];
        $chunkWidths = [];
        $current = '';
        $currentWidth = 0.0;

        $len = strlen($token);
        for ($i = 0; $i < $len; $i++) {
            $char = $token[$i];
            $charWidth = $metrics->charWidth(ord($char), $size);
            if ($currentWidth + $charWidth > $innerW + 0.0001 && $current !== '') {
                $chunks[] = $current;
                $chunkWidths[] = $currentWidth;
                $current = $char;
                $currentWidth = $charWidth;
            } else {
                $current .= $char;
                $currentWidth += $charWidth;
            }
        }
        $chunks[] = $current;
        $chunkWidths[] = $currentWidth;

        return [$chunks, $chunkWidths];
    }

    private function wrapTextCustom(string $rawText, float $innerW, ParsedTtf $ttf, float $size): WrapResult
    {
        $paragraphs = explode("\n", $rawText);

        $lines = [];
        $widths = [];
        $brokenWords = 0;

        foreach ($paragraphs as $paragraph) {
            if ($paragraph === '') {
                $lines[] = '';
                $widths[] = 0.0;
                continue;
            }

            $tokens = preg_split('/(\s+)/u', $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE);
            $tokens = $tokens === false ? [$paragraph] : array_values(array_filter(
                $tokens,
                static fn(string $t): bool => $t !== '',
            ));

            $currentLine = '';
            $currentWidth = 0.0;

            foreach ($tokens as $token) {
                $tokenWidth = self::stringWidthCustom($token, $ttf, $size);
                $isSpace = ctype_space($token);

                if ($currentWidth + $tokenWidth <= $innerW + 0.0001) {
                    $currentLine .= $token;
                    $currentWidth += $tokenWidth;
                    continue;
                }

                if ($isSpace) {
                    $lines[] = $currentLine;
                    $widths[] = $currentWidth;
                    $currentLine = '';
                    $currentWidth = 0.0;
                    continue;
                }

                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                    $widths[] = $currentWidth;
                    $currentLine = '';
                    $currentWidth = 0.0;
                }

                if ($tokenWidth > $innerW) {
                    $brokenWords++;
                    [$chunks, $chunkWidths] = self::forceBreakWordCustom($token, $innerW, $ttf, $size);
                    $lastIndex = count($chunks) - 1;
                    for ($i = 0; $i < $lastIndex; $i++) {
                        $lines[] = $chunks[$i];
                        $widths[] = $chunkWidths[$i];
                    }
                    $currentLine = $chunks[$lastIndex];
                    $currentWidth = $chunkWidths[$lastIndex];
                } else {
                    $currentLine = $token;
                    $currentWidth = $tokenWidth;
                }
            }

            $lines[] = $currentLine;
            $widths[] = $currentWidth;
        }

        return new WrapResult(lines: $lines, widths: $widths, brokenWords: $brokenWords, textOverflow: false);
    }

    private function condenseTextCustom(string $rawText, float $innerW, ParsedTtf $ttf, float $size): CondenseResult
    {
        $paragraphs = explode("\n", $rawText);

        $lines = [];
        $scales = [];

        foreach ($paragraphs as $paragraph) {
            $paraWidth = self::stringWidthCustom($paragraph, $ttf, $size);
            if ($paraWidth <= 0.0 + 0.0001 || $paraWidth <= $innerW + 0.0001) {
                $scale = 100.0;
            } else {
                $scale = ($innerW / $paraWidth) * 100.0;
            }
            $lines[] = $paragraph;
            $scales[] = $scale;
        }

        return new CondenseResult(lines: $lines, scales: $scales);
    }

    private function shrinkTextCustom(
        string $rawText,
        float $innerW,
        ParsedTtf $ttf,
        float $originalSize,
        ?float $customLeading = null,
    ): ShrinkResult {
        $paragraphs = explode("\n", $rawText);

        $maxWidth = 0.0;
        foreach ($paragraphs as $paragraph) {
            $w = self::stringWidthCustom($paragraph, $ttf, $originalSize);
            if ($w > $maxWidth) {
                $maxWidth = $w;
            }
        }

        if ($maxWidth <= 0.0 + 0.0001 || $maxWidth <= $innerW + 0.0001) {
            $effectiveSize = $originalSize;
            $textOverflow = false;
        } else {
            $ratio = $innerW / $maxWidth;
            $effectiveSize = $originalSize * $ratio;
            $minSize = $originalSize / 100.0;
            if ($effectiveSize < $minSize) {
                $effectiveSize = $minSize;
                $textOverflow = true;
            } else {
                $textOverflow = false;
            }
        }

        $effectiveLeading = $customLeading ?? ($effectiveSize * 1.2);

        $widths = [];
        foreach ($paragraphs as $paragraph) {
            $widths[] = self::stringWidthCustom($paragraph, $ttf, $effectiveSize);
        }

        return new ShrinkResult(
            lines: $paragraphs,
            widths: $widths,
            effectiveSize: $effectiveSize,
            effectiveLeading: $effectiveLeading,
            textOverflow: $textOverflow,
        );
    }

    /**
     * @param list<string>      $lines raw UTF-8 lines (NOT yet hex-encoded)
     * @param list<float>       $widths line widths in points
     * @param list<float>|null  $scales per-line Tz percent (Fit::CONDENSE), null otherwise
     */
    private function emitTextCustom(
        ParsedTtf $customTtf,
        array $lines,
        array $widths,
        ?array $scales,
        float $effectiveSize,
        float $effectiveLeading,
        float $cellX,
        float $cellY,
        float $cellW,
        float $cellH,
        CellPadding $padding,
        TextAlign $align,
        VerticalAlign $verticalAlign,
        ?Color $textColor,
        string $fontShortName,
    ): void {
        $lineCount = count($lines);
        $unitsPerEm = $customTtf->unitsPerEm;
        $capHeight = $customTtf->capHeight * $effectiveSize / $unitsPerEm;
        $emAbove = $effectiveSize;
        $descentAbs = abs($customTtf->descent * $effectiveSize / $unitsPerEm);
        $capBlockHeight = $capHeight + ($lineCount - 1) * $effectiveLeading;

        $firstBaseline = match ($verticalAlign) {
            VerticalAlign::TOP    => $cellY + $padding->top + $emAbove,
            VerticalAlign::MIDDLE => $cellY + ($cellH - $capBlockHeight) / 2.0 + $capHeight,
            VerticalAlign::BOTTOM => $cellY + $cellH - $padding->bottom - $descentAbs
                - ($lineCount - 1) * $effectiveLeading,
        };

        if ($textColor !== null) {
            $this->stream->append($textColor->toPdfOperator(stroke: false));
        }

        $this->stream->append(Operators::beginText());
        $this->stream->append(Operators::setFontAndSize($fontShortName, $effectiveSize));
        $this->stream->append(Operators::setTextLeading($effectiveLeading));

        foreach ($lines as $i => $line) {
            $lineWidth = $widths[$i];
            $lineX = match ($align) {
                TextAlign::LEFT   => $cellX + $padding->left,
                TextAlign::CENTER => $cellX + ($cellW - $lineWidth) / 2.0,
                TextAlign::RIGHT  => $cellX + $cellW - $padding->right - $lineWidth,
            };
            $lineBaseline = $firstBaseline + $i * $effectiveLeading;
            $this->stream->append(Operators::textMatrix(1, 0, 0, -1, $lineX, $lineBaseline));
            if ($scales !== null) {
                $this->stream->append(Operators::setHorizontalScaling($scales[$i]));
            }
            $hex = strtoupper(bin2hex(Utf8ToCidEncoder::encode($line, $customTtf)));
            $this->stream->append(Operators::showTextHex($hex));
        }

        $this->stream->append(Operators::endText());
    }

    private static function stringWidthCustom(string $utf8, ParsedTtf $ttf, float $size): float
    {
        $totalEm = 0;
        foreach (Utf8::codepoints($utf8) as [$cp, $_]) {
            $gid = $cp >= 0 ? ($ttf->cmap[$cp] ?? 0) : 0;
            $totalEm += $ttf->advanceWidthsByGid[$gid] ?? 0;
        }
        return $totalEm * $size / $ttf->unitsPerEm;
    }

    /**
     * @return array{0: list<string>, 1: list<float>}
     */
    private static function forceBreakWordCustom(string $token, float $innerW, ParsedTtf $ttf, float $size): array
    {
        $chunks = [];
        $chunkWidths = [];
        $current = '';
        $currentWidth = 0.0;

        $offset = 0;
        foreach (Utf8::codepoints($token) as [$_, $cpLen]) {
            $charBytes = substr($token, $offset, $cpLen);
            $offset += $cpLen;

            $charWidth = self::stringWidthCustom($charBytes, $ttf, $size);
            if ($currentWidth + $charWidth > $innerW + 0.0001 && $current !== '') {
                $chunks[] = $current;
                $chunkWidths[] = $currentWidth;
                $current = $charBytes;
                $currentWidth = $charWidth;
            } else {
                $current .= $charBytes;
                $currentWidth += $charWidth;
            }
        }
        $chunks[] = $current;
        $chunkWidths[] = $currentWidth;

        return [$chunks, $chunkWidths];
    }
}
