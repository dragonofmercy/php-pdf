<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * SVG gradient spread mode (`spreadMethod` attribute on
 * <linearGradient>/<radialGradient>). PAD is the default and renders the
 * gradient by extending the edge stops past the gradient region. REFLECT and
 * REPEAT are implemented by stop replication + coord extension via
 * GradientSpread::expand, then rendered as PAD by PDF's native /Extend.
 *
 * @internal
 */
enum SpreadMethod: string
{
    case PAD = 'pad';
    case REFLECT = 'reflect';
    case REPEAT = 'repeat';

    public static function tryFromName(?string $raw): self
    {
        if ($raw === null) {
            return self::PAD;
        }
        return self::tryFrom($raw) ?? self::PAD;
    }
}
