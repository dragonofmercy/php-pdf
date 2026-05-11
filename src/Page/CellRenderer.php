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
    ): CellResult {
        $innerW = max(0.0, $w - $padding->left - $padding->right);

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
                    foreach ($lines as $i => $line) {
                        $paraWidth = $engine->measure($line, $size);
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
                TextAlign::LEFT   => $cellX + $padding->left,
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
                    [$chunks, $chunkWidths] = $engine->splitForceBreak($token, $innerW, $size);
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

    public function condenseText(string $rawText, float $innerW, FontEngine $engine, float $size): CondenseResult
    {
        $paragraphs = explode("\n", $rawText);

        $lines = [];
        $scales = [];

        foreach ($paragraphs as $paragraph) {
            $paraWidth = $engine->measure($paragraph, $size);
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

    public function shrinkText(
        string $rawText,
        float $innerW,
        FontEngine $engine,
        float $originalSize,
        ?float $customLeading = null,
    ): ShrinkResult {
        $paragraphs = explode("\n", $rawText);

        $maxWidth = 0.0;
        foreach ($paragraphs as $paragraph) {
            $w = $engine->measure($paragraph, $originalSize);
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
            $widths[] = $engine->measure($paragraph, $effectiveSize);
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
