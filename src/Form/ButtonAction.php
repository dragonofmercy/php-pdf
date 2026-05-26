<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * The action triggered when a push button is clicked. Immutable; build via the
 * named constructors: open a URL, reset the whole form, or submit the form.
 */
final readonly class ButtonAction
{
    private function __construct(
        private ButtonActionType $type,
        private ?string $url,
        private ?int $flags = null,
    ) {
    }

    public static function openUrl(string $url): self
    {
        if ($url === '') {
            throw new PdfException('ButtonAction::openUrl requires a non-empty URL');
        }
        return new self(ButtonActionType::OpenUrl, $url);
    }

    public static function resetForm(): self
    {
        return new self(ButtonActionType::ResetForm, null);
    }

    public static function submit(string $url, SubmitFormat $format = SubmitFormat::FDF, bool $get = false): self
    {
        if ($url === '') {
            throw new PdfException('ButtonAction::submit requires a non-empty URL');
        }
        $flags = match ($format) {
            SubmitFormat::FDF  => 0,
            SubmitFormat::HTML => 4,    // ExportFormat (bit 3)
            SubmitFormat::XFDF => 32,   // XFDF (bit 6)
            SubmitFormat::PDF  => 256,  // SubmitPDF (bit 9)
        };
        if ($get) {
            $flags |= 8;                // GetMethod (bit 4)
        }
        return new self(ButtonActionType::SubmitForm, $url, $flags);
    }

    public function type(): ButtonActionType
    {
        return $this->type;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function flags(): ?int
    {
        return $this->flags;
    }
}
