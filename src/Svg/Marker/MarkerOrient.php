<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Marker;

/** @internal */
final readonly class MarkerOrient
{
    public function __construct(
        public MarkerOrientMode $mode,
        public float $angleDeg,
    ) {}

    public static function angle(float $deg): self
    {
        return new self(MarkerOrientMode::NUMBER, $deg);
    }

    public static function auto(): self
    {
        return new self(MarkerOrientMode::AUTO, 0.0);
    }

    public static function autoStartReverse(): self
    {
        return new self(MarkerOrientMode::AUTO_START_REVERSE, 0.0);
    }

    public static function parse(?string $raw): self
    {
        if ($raw === null) {
            return self::angle(0.0);
        }
        $raw = trim($raw);
        if ($raw === 'auto') {
            return self::auto();
        }
        if ($raw === 'auto-start-reverse') {
            return self::autoStartReverse();
        }
        if (is_numeric($raw)) {
            return self::angle((float) $raw);
        }
        return self::angle(0.0);
    }
}
