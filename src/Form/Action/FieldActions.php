<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Action;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Immutable container mapping AcroForm widget triggers to JavaScript. Built
 * fluently; every mutator returns a new instance. Value triggers K/F/V/C come
 * from the Format/Calculate/Validate helpers (or raw keystroke); E/X/D/U/Fo/Bl
 * are raw mouse/focus JavaScript. The emitter renders this as a /AA dictionary.
 *
 * Trigger letters: K keystroke, F format, V validate, C calculate, E mouse-enter,
 * X mouse-exit, D mouse-down, U mouse-up, Fo focus, Bl blur.
 */
final readonly class FieldActions
{
    /** Canonical emission order. @var list<string> */
    private const array ORDER = ['K', 'F', 'V', 'C', 'E', 'X', 'D', 'U', 'Fo', 'Bl'];

    /** @param array<string, string> $scripts trigger letter => JavaScript */
    private function __construct(private array $scripts) {}

    public static function new(): self
    {
        return new self([]);
    }

    public function format(Format $f): self
    {
        return $this->with('K', $f->keystrokeJs())->with('F', $f->formatJs());
    }

    public function calculate(Calculate $c): self
    {
        return $this->with('C', $c->js());
    }

    public function validate(Validate $v): self
    {
        return $this->with('V', $v->js());
    }

    public function keystroke(string $js): self
    {
        return $this->withRaw('K', 'keystroke', $js);
    }

    public function onMouseEnter(string $js): self { return $this->withRaw('E', 'mouse-enter', $js); }
    public function onMouseExit(string $js): self { return $this->withRaw('X', 'mouse-exit', $js); }
    public function onMouseDown(string $js): self { return $this->withRaw('D', 'mouse-down', $js); }
    public function onMouseUp(string $js): self { return $this->withRaw('U', 'mouse-up', $js); }
    public function onFocus(string $js): self { return $this->withRaw('Fo', 'focus', $js); }
    public function onBlur(string $js): self { return $this->withRaw('Bl', 'blur', $js); }

    /** @return array<string, string> trigger letter => JavaScript, in canonical order */
    public function scripts(): array
    {
        $out = [];
        foreach (self::ORDER as $trigger) {
            if (isset($this->scripts[$trigger])) {
                $out[$trigger] = $this->scripts[$trigger];
            }
        }
        return $out;
    }

    public function hasCalculate(): bool
    {
        return isset($this->scripts['C']);
    }

    private function withRaw(string $trigger, string $label, string $js): self
    {
        if ($js === '') {
            throw new PdfException(sprintf('FieldActions: %s JavaScript cannot be empty', $label));
        }
        return $this->with($trigger, $js);
    }

    private function with(string $trigger, string $js): self
    {
        if (isset($this->scripts[$trigger])) {
            throw new PdfException(sprintf(
                "FieldActions: trigger '%s' is already set (a previous call also targets it)",
                $trigger,
            ));
        }
        return new self([...$this->scripts, $trigger => $js]);
    }
}
