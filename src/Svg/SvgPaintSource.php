<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Marker for anything a fill or stroke can resolve to: a solid SvgColor or
 * an SvgGradient. Lets SvgPaint carry either without union-typing every site.
 *
 * @internal
 */
interface SvgPaintSource {}
