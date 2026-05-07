<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

/**
 * Shared lookup tables for EAN-13 and EAN-8 module encoding (ISO/IEC 15420).
 *
 * SET_A is the left-side odd-parity encoding, SET_B is the left-side
 * even-parity encoding, SET_C is the right-side encoding (complement of A).
 * LEFT_PARITY is the EAN-13 first-digit parity pattern table (Table 1).
 *
 * @internal
 */
final class EanSymbols
{
    /** ISO 15420 set A (odd parity) -- 7-bit widths for digits 0-9. */
    public const array SET_A = [
        [false, false, false, true, true, false, true],   // 0 -> 0001101
        [false, false, true, true, false, false, true],   // 1 -> 0011001
        [false, false, true, false, false, true, true],   // 2 -> 0010011
        [false, true, true, true, true, false, true],     // 3 -> 0111101
        [false, true, false, false, false, true, true],   // 4 -> 0100011
        [false, true, true, false, false, false, true],   // 5 -> 0110001
        [false, true, false, true, true, true, true],     // 6 -> 0101111
        [false, true, true, true, false, true, true],     // 7 -> 0111011
        [false, true, true, false, true, true, true],     // 8 -> 0110111
        [false, false, false, true, false, true, true],   // 9 -> 0001011
    ];

    /** ISO 15420 set B (even parity) -- mirror of C read backwards. */
    public const array SET_B = [
        [false, true, false, false, true, true, true],   // 0 -> 0100111
        [false, true, true, false, false, true, true],   // 1 -> 0110011
        [false, false, true, true, false, true, true],   // 2 -> 0011011
        [false, true, false, false, false, false, true], // 3 -> 0100001
        [false, false, true, true, true, false, true],   // 4 -> 0011101
        [false, true, true, true, false, false, true],   // 5 -> 0111001
        [false, false, false, false, true, false, true], // 6 -> 0000101
        [false, false, true, false, false, false, true], // 7 -> 0010001
        [false, false, false, true, false, false, true], // 8 -> 0001001
        [false, false, true, false, true, true, true],   // 9 -> 0010111
    ];

    /** ISO 15420 set C (right side, complement of A). */
    public const array SET_C = [
        [true, true, true, false, false, true, false],   // 0 -> 1110010
        [true, true, false, false, true, true, false],   // 1 -> 1100110
        [true, true, false, true, true, false, false],   // 2 -> 1101100
        [true, false, false, false, false, true, false], // 3 -> 1000010
        [true, false, true, true, true, false, false],   // 4 -> 1011100
        [true, false, false, true, true, true, false],   // 5 -> 1001110
        [true, false, true, false, false, false, false], // 6 -> 1010000
        [true, false, false, false, true, false, false], // 7 -> 1000100
        [true, false, false, true, false, false, false], // 8 -> 1001000
        [true, true, true, false, true, false, false],   // 9 -> 1110100
    ];

    /**
     * Parity pattern for the 6 left-side digits, indexed by the first digit (0-9).
     * 'A' = use SET_A, 'B' = use SET_B. ISO 15420 Table 1.
     */
    public const array LEFT_PARITY = [
        'AAAAAA', // 0
        'AABABB', // 1
        'AABBAB', // 2
        'AABBBA', // 3
        'ABAABB', // 4
        'ABBAAB', // 5
        'ABBBAA', // 6
        'ABABAB', // 7
        'ABABBA', // 8
        'ABBABA', // 9
    ];
}
