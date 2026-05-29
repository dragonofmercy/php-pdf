<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Immutable styling configuration consumed by the Markdown renderer.
 *
 * Heading and body font sizes are in POINTS (typographic convention); every
 * spacing and indent value is a document-unit float. Sensible defaults let
 * most callers use ::default() and tweak only what they need via the with*()
 * mutators, each of which rebuilds the object through the validating
 * constructor.
 */
final readonly class MarkdownStyle
{
    /**
     * @param array<int,float> $headingSizes keys 1..6, font size in points
     * @param list<string>     $bulletGlyphs glyphs cycled per nesting depth
     */
    private function __construct(
        public array $headingSizes,
        public ?float $bodySize,
        public float $paragraphSpacing,
        public float $headingSpacingBefore,
        public float $headingSpacingAfter,
        public float $listItemSpacing,
        public float $blockSpacing,
        public Font $codeFont,
        public ?Color $codeBackground,
        public float $codeBlockPadding,
        public Color $linkColor,
        public bool $linkUnderline,
        public Color $blockQuoteBarColor,
        public float $blockQuoteBarWidth,
        public float $blockQuoteIndent,
        public float $listIndent,
        public array $bulletGlyphs,
    ) {
        for ($level = 1; $level <= 6; $level++) {
            if (!array_key_exists($level, $headingSizes)) {
                throw new PdfException("Markdown heading size for level {$level} is missing");
            }
            $size = $headingSizes[$level];
            if ($size <= 0) {
                throw new PdfException("Markdown heading size for level {$level} must be positive, got {$size}");
            }
        }

        if ($bodySize !== null && $bodySize <= 0) {
            throw new PdfException("Markdown body size must be positive, got {$bodySize}");
        }

        foreach (
            [
                'paragraph spacing' => $paragraphSpacing,
                'heading spacing before' => $headingSpacingBefore,
                'heading spacing after' => $headingSpacingAfter,
                'list item spacing' => $listItemSpacing,
                'block spacing' => $blockSpacing,
                'code block padding' => $codeBlockPadding,
                'block quote bar width' => $blockQuoteBarWidth,
                'block quote indent' => $blockQuoteIndent,
                'list indent' => $listIndent,
            ] as $label => $value
        ) {
            if ($value < 0) {
                throw new PdfException("Markdown {$label} cannot be negative, got {$value}");
            }
        }

        if ($bulletGlyphs === []) {
            throw new PdfException('Markdown bullet glyphs cannot be empty');
        }
    }

    public static function default(): self
    {
        return new self(
            headingSizes: [1 => 24.0, 2 => 20.0, 3 => 16.0, 4 => 14.0, 5 => 12.0, 6 => 11.0],
            bodySize: null,
            paragraphSpacing: 3.0,
            headingSpacingBefore: 4.0,
            headingSpacingAfter: 2.0,
            listItemSpacing: 1.0,
            blockSpacing: 3.0,
            codeFont: Font::courier(),
            codeBackground: Color::rgb(245, 245, 245),
            codeBlockPadding: 2.0,
            linkColor: Color::rgb(0, 0, 238),
            linkUnderline: true,
            blockQuoteBarColor: Color::rgb(200, 200, 200),
            blockQuoteBarWidth: 1.0,
            blockQuoteIndent: 4.0,
            listIndent: 6.0,
            bulletGlyphs: ['-', '*', '+'],
        );
    }

    public function withHeadingSize(int $level, float $size): self
    {
        $sizes = $this->headingSizes;
        $sizes[$level] = $size;

        return new self(
            headingSizes: $sizes,
            bodySize: $this->bodySize,
            paragraphSpacing: $this->paragraphSpacing,
            headingSpacingBefore: $this->headingSpacingBefore,
            headingSpacingAfter: $this->headingSpacingAfter,
            listItemSpacing: $this->listItemSpacing,
            blockSpacing: $this->blockSpacing,
            codeFont: $this->codeFont,
            codeBackground: $this->codeBackground,
            codeBlockPadding: $this->codeBlockPadding,
            linkColor: $this->linkColor,
            linkUnderline: $this->linkUnderline,
            blockQuoteBarColor: $this->blockQuoteBarColor,
            blockQuoteBarWidth: $this->blockQuoteBarWidth,
            blockQuoteIndent: $this->blockQuoteIndent,
            listIndent: $this->listIndent,
            bulletGlyphs: $this->bulletGlyphs,
        );
    }

    public function withBodySize(?float $size): self
    {
        return new self(
            headingSizes: $this->headingSizes,
            bodySize: $size,
            paragraphSpacing: $this->paragraphSpacing,
            headingSpacingBefore: $this->headingSpacingBefore,
            headingSpacingAfter: $this->headingSpacingAfter,
            listItemSpacing: $this->listItemSpacing,
            blockSpacing: $this->blockSpacing,
            codeFont: $this->codeFont,
            codeBackground: $this->codeBackground,
            codeBlockPadding: $this->codeBlockPadding,
            linkColor: $this->linkColor,
            linkUnderline: $this->linkUnderline,
            blockQuoteBarColor: $this->blockQuoteBarColor,
            blockQuoteBarWidth: $this->blockQuoteBarWidth,
            blockQuoteIndent: $this->blockQuoteIndent,
            listIndent: $this->listIndent,
            bulletGlyphs: $this->bulletGlyphs,
        );
    }

    public function withParagraphSpacing(float $spacing): self
    {
        return new self(
            headingSizes: $this->headingSizes,
            bodySize: $this->bodySize,
            paragraphSpacing: $spacing,
            headingSpacingBefore: $this->headingSpacingBefore,
            headingSpacingAfter: $this->headingSpacingAfter,
            listItemSpacing: $this->listItemSpacing,
            blockSpacing: $this->blockSpacing,
            codeFont: $this->codeFont,
            codeBackground: $this->codeBackground,
            codeBlockPadding: $this->codeBlockPadding,
            linkColor: $this->linkColor,
            linkUnderline: $this->linkUnderline,
            blockQuoteBarColor: $this->blockQuoteBarColor,
            blockQuoteBarWidth: $this->blockQuoteBarWidth,
            blockQuoteIndent: $this->blockQuoteIndent,
            listIndent: $this->listIndent,
            bulletGlyphs: $this->bulletGlyphs,
        );
    }

    public function withCodeFont(Font $font): self
    {
        return new self(
            headingSizes: $this->headingSizes,
            bodySize: $this->bodySize,
            paragraphSpacing: $this->paragraphSpacing,
            headingSpacingBefore: $this->headingSpacingBefore,
            headingSpacingAfter: $this->headingSpacingAfter,
            listItemSpacing: $this->listItemSpacing,
            blockSpacing: $this->blockSpacing,
            codeFont: $font,
            codeBackground: $this->codeBackground,
            codeBlockPadding: $this->codeBlockPadding,
            linkColor: $this->linkColor,
            linkUnderline: $this->linkUnderline,
            blockQuoteBarColor: $this->blockQuoteBarColor,
            blockQuoteBarWidth: $this->blockQuoteBarWidth,
            blockQuoteIndent: $this->blockQuoteIndent,
            listIndent: $this->listIndent,
            bulletGlyphs: $this->bulletGlyphs,
        );
    }

    public function withCodeBackground(?Color $color): self
    {
        return new self(
            headingSizes: $this->headingSizes,
            bodySize: $this->bodySize,
            paragraphSpacing: $this->paragraphSpacing,
            headingSpacingBefore: $this->headingSpacingBefore,
            headingSpacingAfter: $this->headingSpacingAfter,
            listItemSpacing: $this->listItemSpacing,
            blockSpacing: $this->blockSpacing,
            codeFont: $this->codeFont,
            codeBackground: $color,
            codeBlockPadding: $this->codeBlockPadding,
            linkColor: $this->linkColor,
            linkUnderline: $this->linkUnderline,
            blockQuoteBarColor: $this->blockQuoteBarColor,
            blockQuoteBarWidth: $this->blockQuoteBarWidth,
            blockQuoteIndent: $this->blockQuoteIndent,
            listIndent: $this->listIndent,
            bulletGlyphs: $this->bulletGlyphs,
        );
    }

    public function withLinkColor(Color $color): self
    {
        return new self(
            headingSizes: $this->headingSizes,
            bodySize: $this->bodySize,
            paragraphSpacing: $this->paragraphSpacing,
            headingSpacingBefore: $this->headingSpacingBefore,
            headingSpacingAfter: $this->headingSpacingAfter,
            listItemSpacing: $this->listItemSpacing,
            blockSpacing: $this->blockSpacing,
            codeFont: $this->codeFont,
            codeBackground: $this->codeBackground,
            codeBlockPadding: $this->codeBlockPadding,
            linkColor: $color,
            linkUnderline: $this->linkUnderline,
            blockQuoteBarColor: $this->blockQuoteBarColor,
            blockQuoteBarWidth: $this->blockQuoteBarWidth,
            blockQuoteIndent: $this->blockQuoteIndent,
            listIndent: $this->listIndent,
            bulletGlyphs: $this->bulletGlyphs,
        );
    }

    public function withLinkUnderline(bool $underline): self
    {
        return new self(
            headingSizes: $this->headingSizes,
            bodySize: $this->bodySize,
            paragraphSpacing: $this->paragraphSpacing,
            headingSpacingBefore: $this->headingSpacingBefore,
            headingSpacingAfter: $this->headingSpacingAfter,
            listItemSpacing: $this->listItemSpacing,
            blockSpacing: $this->blockSpacing,
            codeFont: $this->codeFont,
            codeBackground: $this->codeBackground,
            codeBlockPadding: $this->codeBlockPadding,
            linkColor: $this->linkColor,
            linkUnderline: $underline,
            blockQuoteBarColor: $this->blockQuoteBarColor,
            blockQuoteBarWidth: $this->blockQuoteBarWidth,
            blockQuoteIndent: $this->blockQuoteIndent,
            listIndent: $this->listIndent,
            bulletGlyphs: $this->bulletGlyphs,
        );
    }

    /** Null width/indent keep the current value. */
    public function withBlockQuoteBar(Color $color, ?float $width = null, ?float $indent = null): self
    {
        return new self(
            headingSizes: $this->headingSizes,
            bodySize: $this->bodySize,
            paragraphSpacing: $this->paragraphSpacing,
            headingSpacingBefore: $this->headingSpacingBefore,
            headingSpacingAfter: $this->headingSpacingAfter,
            listItemSpacing: $this->listItemSpacing,
            blockSpacing: $this->blockSpacing,
            codeFont: $this->codeFont,
            codeBackground: $this->codeBackground,
            codeBlockPadding: $this->codeBlockPadding,
            linkColor: $this->linkColor,
            linkUnderline: $this->linkUnderline,
            blockQuoteBarColor: $color,
            blockQuoteBarWidth: $width ?? $this->blockQuoteBarWidth,
            blockQuoteIndent: $indent ?? $this->blockQuoteIndent,
            listIndent: $this->listIndent,
            bulletGlyphs: $this->bulletGlyphs,
        );
    }

    public function withListIndent(float $indent): self
    {
        return new self(
            headingSizes: $this->headingSizes,
            bodySize: $this->bodySize,
            paragraphSpacing: $this->paragraphSpacing,
            headingSpacingBefore: $this->headingSpacingBefore,
            headingSpacingAfter: $this->headingSpacingAfter,
            listItemSpacing: $this->listItemSpacing,
            blockSpacing: $this->blockSpacing,
            codeFont: $this->codeFont,
            codeBackground: $this->codeBackground,
            codeBlockPadding: $this->codeBlockPadding,
            linkColor: $this->linkColor,
            linkUnderline: $this->linkUnderline,
            blockQuoteBarColor: $this->blockQuoteBarColor,
            blockQuoteBarWidth: $this->blockQuoteBarWidth,
            blockQuoteIndent: $this->blockQuoteIndent,
            listIndent: $indent,
            bulletGlyphs: $this->bulletGlyphs,
        );
    }
}
