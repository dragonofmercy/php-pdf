<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Leaf nodes (paths, primitives). Each carries a local transform (optional)
 * and a fully resolved paint state. Geometry-specific fields live on concrete
 * classes; the renderer dispatches via `match($shape::class)`.
 */
interface SvgShape extends SvgNode
{
    public function transform(): ?SvgMatrix;

    public function paint(): SvgPaint;
}
