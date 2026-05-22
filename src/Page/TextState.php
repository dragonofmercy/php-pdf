<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Page;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\Custom\FontResolver;
use DragonOfMercy\PhpPdf\Font\FontEngine;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Font\StandardFontEngine;

/**
 * The font state machine for a page: the current font, size, leading, and the
 * resolved FontEngine. Owns font selection, engine construction, capture/restore
 * (for header/footer bracketing), and string measurement. Holds no content
 * stream - Page emits text operators using the engine this exposes.
 *
 * @internal
 */
final class TextState
{
    private ?Font $currentFont = null;
    private ?float $currentSize = null;
    private ?float $customLeading = null;
    private ?FontEngine $currentFontEngine = null;

    public function __construct(
        private readonly MetricsRegistry $metricsRegistry,
        private readonly ?FontResolver $fontResolver,
        ?Font $defaultFont,
        ?float $defaultSize,
    ) {
        if (($defaultFont === null) !== ($defaultSize === null)) {
            throw new PdfException('Page default font requires both font and size, or neither');
        }
        if ($defaultFont !== null && $defaultSize !== null) {
            if ($defaultSize <= 0) {
                throw new PdfException('Default font size must be positive, got ' . $defaultSize);
            }
            $this->currentFont = $defaultFont;
            $this->currentSize = $defaultSize;
        }
        if ($this->currentFont !== null) {
            if ($this->currentFont->isCustom() && $this->fontResolver === null) {
                throw new PdfException('Page received a custom Font as default but no FontResolver from Document');
            }
            $this->currentFontEngine = $this->buildEngineFor($this->currentFont);
        }
    }

    public function getFont(): Font
    {
        if ($this->currentFont === null) {
            throw new PdfException('No font set: call setFont() first');
        }
        return $this->currentFont;
    }

    public function getFontSize(): float
    {
        if ($this->currentSize === null) {
            throw new PdfException('No font set: call setFont() first');
        }
        return $this->currentSize;
    }

    public function setFont(Font $font, ?float $size): void
    {
        if ($size === null) {
            if ($this->currentSize === null) {
                throw new PdfException('Font size is required when no font has been set previously');
            }
            $size = $this->currentSize;
        } elseif ($size <= 0) {
            throw new PdfException('Font size must be positive, got ' . $size);
        }
        if ($font->isCustom() && $this->fontResolver === null) {
            throw new PdfException(
                "Cannot use custom font '" . $font->requireCustomAlias() . "': "
                . 'Call Document::registerFontFamily() first.',
            );
        }
        $this->currentFontEngine = $this->buildEngineFor($font);
        $this->currentFont = $font;
        $this->currentSize = $size;
        $this->customLeading = null;
    }

    public function setLeading(float $leading): void
    {
        $this->customLeading = $leading;
    }

    public function currentFont(): ?Font
    {
        return $this->currentFont;
    }

    public function currentSize(): ?float
    {
        return $this->currentSize;
    }

    public function customLeading(): ?float
    {
        return $this->customLeading;
    }

    public function activeEngine(): FontEngine
    {
        if ($this->currentFontEngine === null) {
            throw new PdfException('No active font on this page');
        }
        return $this->currentFontEngine;
    }

    public function buildEngineFor(Font $font): FontEngine
    {
        return $this->fontResolver !== null
            ? $this->fontResolver->resolveEngine($font)
            : new StandardFontEngine($font, $this->metricsRegistry->metricsFor($font));
    }

    /**
     * @return array{font: ?Font, size: ?float, leading: ?float, engine: ?FontEngine}
     */
    public function capture(): array
    {
        return [
            'font' => $this->currentFont,
            'size' => $this->currentSize,
            'leading' => $this->customLeading,
            'engine' => $this->currentFontEngine,
        ];
    }

    /**
     * @param array{font: ?Font, size: ?float, leading: ?float, engine: ?FontEngine} $state
     */
    public function restore(array $state): void
    {
        $this->currentFont = $state['font'];
        $this->currentSize = $state['size'];
        $this->customLeading = $state['leading'];
        $this->currentFontEngine = $state['engine'];
    }

    /**
     * Width of the longest line of $text in points, using the given font/size.
     * Reuses the active engine when $font is the current font.
     */
    public function measureMaxLineWidthPt(string $text, Font $font, float $size): float
    {
        if ($font->isCustom() && $this->fontResolver === null) {
            throw new PdfException('Cannot measure custom Font without a registered family');
        }
        $engine = $font === $this->currentFont && $this->currentFontEngine !== null
            ? $this->currentFontEngine
            : $this->buildEngineFor($font);

        $maxWidthPt = 0.0;
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            $w = $engine->measure($line, $size);
            if ($w > $maxWidthPt) {
                $maxWidthPt = $w;
            }
        }
        return $maxWidthPt;
    }
}
