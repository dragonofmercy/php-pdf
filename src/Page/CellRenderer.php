<?php

declare(strict_types=1);

namespace PhpPdf\Page;

use PhpPdf\Font;
use PhpPdf\Font\FontMetrics;
use PhpPdf\Font\MetricsRegistry;
use PhpPdf\Font\WinAnsiEncoder;

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
     * Full cell rendering pipeline: lays out text, emits PDF operators into the
     * content stream, and returns a CellResult. Implemented in Task 13.
     *
     * @internal
     */
    public function render(): never
    {
        // Task 13 will replace this stub with the full emission pipeline.
        // The stream property is appended to here (a no-op empty append) to
        // satisfy static analysis until the full implementation lands.
        $this->stream->append('');
        throw new \LogicException('CellRenderer::render() is not yet implemented (Task 13).');
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
}
