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

    public static function matrixFor(ViewBox $vb, float $targetW, float $targetH, ?self $par = null): SvgMatrix
    {
        $par ??= self::default();

        if ($par->align === Align::NONE) {
            $sx = $targetW / $vb->width;
            $sy = $targetH / $vb->height;
            return SvgMatrix::translate(-$vb->x * $sx, -$vb->y * $sy)
                ->compose(SvgMatrix::scale($sx, $sy));
        }

        $sxRaw = $targetW / $vb->width;
        $syRaw = $targetH / $vb->height;
        $scale = $par->meetOrSlice === MeetOrSlice::MEET ? min($sxRaw, $syRaw) : max($sxRaw, $syRaw);

        $contentW = $vb->width * $scale;
        $contentH = $vb->height * $scale;

        $dx = match (true) {
            $par->align === Align::X_MIN_Y_MIN, $par->align === Align::X_MIN_Y_MID, $par->align === Align::X_MIN_Y_MAX => 0.0,
            $par->align === Align::X_MAX_Y_MIN, $par->align === Align::X_MAX_Y_MID, $par->align === Align::X_MAX_Y_MAX => $targetW - $contentW,
            default => ($targetW - $contentW) / 2.0,
        };
        $dy = match (true) {
            $par->align === Align::X_MIN_Y_MIN, $par->align === Align::X_MID_Y_MIN, $par->align === Align::X_MAX_Y_MIN => 0.0,
            $par->align === Align::X_MIN_Y_MAX, $par->align === Align::X_MID_Y_MAX, $par->align === Align::X_MAX_Y_MAX => $targetH - $contentH,
            default => ($targetH - $contentH) / 2.0,
        };

        return SvgMatrix::translate($dx - $vb->x * $scale, $dy - $vb->y * $scale)
            ->compose(SvgMatrix::scale($scale, $scale));
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
