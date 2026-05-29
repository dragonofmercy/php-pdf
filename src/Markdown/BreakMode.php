<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Markdown;

/**
 * Controls how the {@see BoxRenderer} reacts when content reaches the bottom of
 * its box. ATOMIC renders the whole AST in a single pass without ever breaking
 * (used to measure a block or to draw something that is known to fit); FLOW
 * splits content across page boundaries at line granularity, driven by a
 * page-break callback supplied to {@see BoxRenderer::render()}.
 */
enum BreakMode
{
    case ATOMIC;
    case FLOW;
}
