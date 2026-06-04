<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Page;

use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\BorderStyle;
use DragonOfMercy\PhpPdf\CellPadding;
use DragonOfMercy\PhpPdf\CellResult;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Fit;
use DragonOfMercy\PhpPdf\Font\FontEngine;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\VerticalAlign;

/**
 * Encapsulates the cell rendering pipeline (wrap/fit/layout/emit). Owned
 * indirectly by Page::cell() - instantiated per call. Path-agnostic: the
 * supplied FontEngine handles WinAnsi vs Identity-H.
 *
 * @internal
 */
final class CellRenderer
{
    public function __construct(
        private readonly ContentStream $stream,
    ) {}

    public function render(
        FontEngine $engine,
        float $size,
        ?float $customLeading,
        float $x,
        float $y,
        ?float $w,
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
        Page $emittingPage,
    ): CellResult {
        $lines = [];
        $widths = [];
        $brokenWords = 0;
        $textOverflow = false;
        $effectiveSize = $size;
        $effectiveLeading = $customLeading ?? ($size * 1.2);
        $scales = null;
        $autoWidth = $w === null;

        // Auto-width: Fit::NONE still needs a paragraph-level widest scan to
        // size the cell before tokenized wrapping. CONDENSE and SHRINK measure
        // each paragraph internally and unconstrain themselves with
        // PHP_FLOAT_MAX, so the widest paragraph can be derived from their
        // results without an extra pass.
        if ($text === '') {
            $w ??= $padding->left + $padding->right;
            $innerW = max(0.0, $w - $padding->left - $padding->right);
            $lineCount = 0;
            $textHeight = 0.0;
        } else {
            if ($autoWidth && $fit === Fit::NONE) {
                $w = $this->widestLineWidth($text, $engine, $size)
                    + $padding->left + $padding->right;
            }
            $innerW = $w === null ? PHP_FLOAT_MAX : max(0.0, $w - $padding->left - $padding->right);

            switch ($fit) {
                case Fit::NONE:
                    $wrap = $this->wrapText($text, $innerW, $engine, $size);
                    $lines = $wrap->lines;
                    $widths = $wrap->widths;
                    $brokenWords = $wrap->brokenWords;
                    break;

                case Fit::CONDENSE:
                    $cond = $this->condenseText($text, $innerW, $engine, $size);
                    $lines = $cond->lines;
                    $scales = $cond->scales;
                    $widths = [];
                    foreach ($cond->widths as $i => $paraWidth) {
                        $widths[] = $paraWidth * $scales[$i] / 100.0;
                    }
                    break;

                case Fit::SHRINK:
                    $shr = $this->shrinkText($text, $innerW, $engine, $size, $customLeading);
                    $lines = $shr->lines;
                    $widths = $shr->widths;
                    $effectiveSize = $shr->effectiveSize;
                    $effectiveLeading = $shr->effectiveLeading;
                    $textOverflow = $shr->textOverflow;
                    break;
            }
            $lineCount = count($lines);

            if ($w === null) {
                $maxLineWidth = 0.0;
                foreach ($widths as $lineWidth) {
                    if ($lineWidth > $maxLineWidth) {
                        $maxLineWidth = $lineWidth;
                    }
                }
                $w = $maxLineWidth + $padding->left + $padding->right;
            }

            $descentAbs = abs($engine->descentAt($effectiveSize));
            $textHeight = $effectiveSize + $descentAbs + ($lineCount - 1) * $effectiveLeading;
        }

        $cellHeight = max($h ?? 0.0, $textHeight + $padding->top + $padding->bottom);

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
            $this->emitText(
                engine: $engine,
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

        $this->stream->append(Operators::restoreState());

        return new CellResult(
            x: $x + $w,
            y: $y + $cellHeight,
            height: $cellHeight,
            lineCount: $lineCount,
            brokenWords: $brokenWords,
            textOverflow: $textOverflow,
            effectiveWidth: $w,
            page: $emittingPage,
        );
    }

    private function widestLineWidth(string $text, FontEngine $engine, float $size): float
    {
        $maxW = 0.0;
        foreach (explode("\n", $text) as $line) {
            $w = $engine->measure($line, $size);
            if ($w > $maxW) {
                $maxW = $w;
            }
        }
        return $maxW;
    }

    private function emitBorders(Border $border, float $x, float $y, float $w, float $h): void
    {
        assert(
            $border->width !== null,
            'Border width must be resolved by Page::cell() before reaching CellRenderer::emitBorders()',
        );
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
     * @param list<float>|null $scales
     */
    private function emitText(
        FontEngine $engine,
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
        $capHeight = $engine->capHeightAt($effectiveSize);
        $emAbove = $effectiveSize;
        $descentAbs = abs($engine->descentAt($effectiveSize));
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
                TextAlign::LEFT, TextAlign::JUSTIFY => $cellX + $padding->left,
                TextAlign::CENTER => $cellX + ($cellW - $lineWidth) / 2.0,
                TextAlign::RIGHT  => $cellX + $cellW - $padding->right - $lineWidth,
            };
            $lineBaseline = $firstBaseline + $i * $effectiveLeading;
            $this->stream->append(Operators::textMatrix(1, 0, 0, -1, $lineX, $lineBaseline));
            if ($scales !== null) {
                $this->stream->append(Operators::setHorizontalScaling($scales[$i]));
            }
            $engine->emitShowText($this->stream, $line);
        }

        $this->stream->append(Operators::endText());
    }

    public function wrapText(string $rawText, float $innerW, FontEngine $engine, float $size): WrapResult
    {
        $paragraphs = explode("\n", $rawText);

        $lines = [];
        $widths = [];
        $justify = [];
        $brokenWords = 0;

        foreach ($paragraphs as $paragraph) {
            if ($paragraph === '') {
                $lines[] = '';
                $widths[] = 0.0;
                $justify[] = false;
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
                $tokenWidth = $engine->measure($token, $size);
                $isSpace = ctype_space($token);

                if ($currentWidth + $tokenWidth <= $innerW + 0.0001) {
                    $currentLine .= $token;
                    $currentWidth += $tokenWidth;
                    continue;
                }

                if ($isSpace) {
                    $lines[] = $currentLine;
                    $widths[] = $currentWidth;
                    $justify[] = true;
                    $currentLine = '';
                    $currentWidth = 0.0;
                    continue;
                }

                if ($currentLine !== '') {
                    $lines[] = $currentLine;
                    $widths[] = $currentWidth;
                    $justify[] = true;
                    $currentLine = '';
                    $currentWidth = 0.0;
                }

                if ($tokenWidth > $innerW) {
                    $brokenWords++;
                    [$chunks, $chunkWidths] = $engine->splitForceBreak($token, $innerW, $size);
                    $lastIndex = count($chunks) - 1;
                    for ($i = 0; $i < $lastIndex; $i++) {
                        $lines[] = $chunks[$i];
                        $widths[] = $chunkWidths[$i];
                        $justify[] = true;
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
            $justify[] = false;
        }

        return new WrapResult(
            lines: $lines,
            widths: $widths,
            justify: $justify,
            brokenWords: $brokenWords,
            textOverflow: false,
        );
    }

    public function condenseText(string $rawText, float $innerW, FontEngine $engine, float $size): CondenseResult
    {
        $paragraphs = explode("\n", $rawText);

        $lines = [];
        $scales = [];
        $widths = [];

        foreach ($paragraphs as $paragraph) {
            $paraWidth = $engine->measure($paragraph, $size);
            if ($paraWidth <= 0.0 + 0.0001 || $paraWidth <= $innerW + 0.0001) {
                $scale = 100.0;
            } else {
                $scale = ($innerW / $paraWidth) * 100.0;
            }
            $lines[] = $paragraph;
            $scales[] = $scale;
            $widths[] = $paraWidth;
        }

        return new CondenseResult(lines: $lines, scales: $scales, widths: $widths);
    }

    public function shrinkText(
        string $rawText,
        float $innerW,
        FontEngine $engine,
        float $originalSize,
        ?float $customLeading = null,
    ): ShrinkResult {
        $paragraphs = explode("\n", $rawText);

        $widthsAtOriginal = [];
        $maxWidth = 0.0;
        foreach ($paragraphs as $paragraph) {
            $w = $engine->measure($paragraph, $originalSize);
            $widthsAtOriginal[] = $w;
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

        if ($effectiveSize === $originalSize) {
            $widths = $widthsAtOriginal;
        } else {
            $widths = [];
            foreach ($paragraphs as $paragraph) {
                $widths[] = $engine->measure($paragraph, $effectiveSize);
            }
        }

        return new ShrinkResult(
            lines: $paragraphs,
            widths: $widths,
            effectiveSize: $effectiveSize,
            effectiveLeading: $effectiveLeading,
            textOverflow: $textOverflow,
        );
    }
}
