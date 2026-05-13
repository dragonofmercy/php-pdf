<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DragonOfMercy\PhpPdf\Exception\PdfException;

final readonly class PreserveAspectRatio
{
    public function __construct(
        public Align $align,
        public MeetOrSlice $meetOrSlice,
    ) {}

    public static function default(): self
    {
        return new self(Align::X_MID_Y_MID, MeetOrSlice::MEET);
    }

    public static function parse(string $value): self
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        if (count($parts) === 0 || $parts[0] === '') {
            return self::default();
        }

        $align = Align::tryFrom($parts[0]);
        if ($align === null) {
            throw new PdfException("Invalid preserveAspectRatio alignment: '{$parts[0]}'");
        }

        $meetOrSlice = MeetOrSlice::MEET;
        if (count($parts) > 1 && $align !== Align::NONE) {
            $candidate = MeetOrSlice::tryFrom($parts[1]);
            if ($candidate === null) {
                throw new PdfException("Invalid preserveAspectRatio meet-or-slice: '{$parts[1]}'");
            }
            $meetOrSlice = $candidate;
        }

        return new self($align, $meetOrSlice);
    }
}
