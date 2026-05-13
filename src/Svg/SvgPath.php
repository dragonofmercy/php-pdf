<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

final readonly class SvgPath implements SvgShape
{
    /**
     * @param list<SvgPathCommand> $commands
     */
    public function __construct(
        private ?SvgMatrix $transform,
        private SvgPaint $paint,
        public array $commands,
    ) {}

    public function transform(): ?SvgMatrix { return $this->transform; }
    public function paint(): SvgPaint { return $this->paint; }
}
