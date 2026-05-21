<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Aztec;

/**
 * Immutable linked-list node used by HighLevelEncoder to record the bits each
 * state has emitted. A token is either a SIMPLE token (raw value + bit count)
 * or a BINARY token (a run of raw bytes copied verbatim from the input, with
 * the BINARY SHIFT codeword + length prefix added at render time).
 *
 * Tokens are immutable and shared between states via the prev pointer, which
 * lets DP states cheaply branch from a common prefix.
 *
 * @internal
 */
final readonly class EncoderToken
{
    public const int TYPE_SIMPLE = 0;
    public const int TYPE_BINARY = 1;

    /**
     * @param int $type one of TYPE_SIMPLE / TYPE_BINARY
     * @param int $a    SIMPLE: bit value; BINARY: start index into the input
     * @param int $b    SIMPLE: bit count; BINARY: byte run length
     */
    public function __construct(
        public ?EncoderToken $prev,
        public int $type,
        public int $a,
        public int $b,
    ) {}

    public static function simple(?EncoderToken $prev, int $value, int $bits): self
    {
        return new self($prev, self::TYPE_SIMPLE, $value, $bits);
    }

    public static function binary(?EncoderToken $prev, int $start, int $count): self
    {
        return new self($prev, self::TYPE_BINARY, $start, $count);
    }
}
