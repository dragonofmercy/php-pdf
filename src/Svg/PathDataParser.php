<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Svg\PathCommand\Arc;
use DragonOfMercy\PhpPdf\Svg\PathCommand\ClosePath;
use DragonOfMercy\PhpPdf\Svg\PathCommand\CubicBezier;
use DragonOfMercy\PhpPdf\Svg\PathCommand\LineTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\MoveTo;
use DragonOfMercy\PhpPdf\Svg\PathCommand\QuadraticBezier;

/**
 * Parses the `d` attribute of an <svg:path> into a flat list of absolute
 * SvgPathCommand objects. H/V are expanded to LineTo, S to CubicBezier (with
 * the reflected control point), T to QuadraticBezier, all relative commands
 * resolved to absolute coordinates. Unknown commands silently truncate the
 * parse (per spec error handling); incomplete parameter lists raise PdfException.
 *
 * The tokenizer treats whitespace and commas as separators between numbers,
 * but lets `-` and `+` act as implicit boundaries (so "10-5" is two tokens).
 */
final class PathDataParser
{
    private const string NUMBER_RX = '/[+-]?(?:\d+\.\d*|\.\d+|\d+)(?:[eE][+-]?\d+)?/A';
    private const string COMMAND_RX = '/[MmLlHhVvCcSsQqTtAaZz]/A';

    /**
     * @return list<SvgPathCommand>
     */
    public static function parse(string $d): array
    {
        $tokens = self::tokenize($d);
        if ($tokens === []) {
            return [];
        }

        $first = $tokens[0];
        if (!is_string($first) || ($first !== 'M' && $first !== 'm')) {
            throw new PdfException('Path data must begin with M or m');
        }

        $cmds = [];
        $i = 0;
        $cx = 0.0;
        $cy = 0.0;
        $startX = 0.0;
        $startY = 0.0;
        $lastCubicC2x = null;
        $lastCubicC2y = null;
        $lastQuadCx = null;
        $lastQuadCy = null;
        $lastCmd = '';

        $n = count($tokens);
        while ($i < $n) {
            $token = $tokens[$i];

            $cmd = null;
            if (is_string($token)) {
                $cmd = $token;
                $i++;
            } elseif ($lastCmd === '') {
                // Should not happen since we already validated first token is M/m.
                throw new PdfException('Unexpected number before any command');
            } else {
                // Implicit re-issue: continue with last command (M/m -> L/l).
                $cmd = match ($lastCmd) {
                    'M' => 'L',
                    'm' => 'l',
                    default => $lastCmd,
                };
            }

            $relative = ctype_lower($cmd);
            switch (strtoupper($cmd)) {
                case 'M':
                    self::ensureNumbers($tokens, $i, 2, $cmd);
                    [$x, $y] = self::pair($tokens, $i, $relative, $cx, $cy);
                    $cmds[] = new MoveTo($x, $y);
                    $cx = $x;
                    $cy = $y;
                    $startX = $x;
                    $startY = $y;
                    $lastCmd = $cmd;
                    $lastCubicC2x = $lastCubicC2y = null;
                    $lastQuadCx = $lastQuadCy = null;
                    break;

                case 'L':
                    self::ensureNumbers($tokens, $i, 2, $cmd);
                    [$x, $y] = self::pair($tokens, $i, $relative, $cx, $cy);
                    $cmds[] = new LineTo($x, $y);
                    $cx = $x;
                    $cy = $y;
                    $lastCmd = $cmd;
                    $lastCubicC2x = $lastCubicC2y = null;
                    $lastQuadCx = $lastQuadCy = null;
                    break;

                case 'H':
                    self::ensureNumbers($tokens, $i, 1, $cmd);
                    $h = self::one($tokens, $i);
                    $x = $relative ? $cx + $h : $h;
                    $cmds[] = new LineTo($x, $cy);
                    $cx = $x;
                    $lastCmd = $cmd;
                    $lastCubicC2x = $lastCubicC2y = null;
                    $lastQuadCx = $lastQuadCy = null;
                    break;

                case 'V':
                    self::ensureNumbers($tokens, $i, 1, $cmd);
                    $v = self::one($tokens, $i);
                    $y = $relative ? $cy + $v : $v;
                    $cmds[] = new LineTo($cx, $y);
                    $cy = $y;
                    $lastCmd = $cmd;
                    $lastCubicC2x = $lastCubicC2y = null;
                    $lastQuadCx = $lastQuadCy = null;
                    break;

                case 'C':
                    self::ensureNumbers($tokens, $i, 6, $cmd);
                    [$c1x, $c1y] = self::pair($tokens, $i, $relative, $cx, $cy);
                    [$c2x, $c2y] = self::pair($tokens, $i, $relative, $cx, $cy);
                    [$x, $y]     = self::pair($tokens, $i, $relative, $cx, $cy);
                    $cmds[] = new CubicBezier($c1x, $c1y, $c2x, $c2y, $x, $y);
                    $lastCubicC2x = $c2x;
                    $lastCubicC2y = $c2y;
                    $lastQuadCx = $lastQuadCy = null;
                    $cx = $x;
                    $cy = $y;
                    $lastCmd = $cmd;
                    break;

                case 'S':
                    self::ensureNumbers($tokens, $i, 4, $cmd);
                    // Reflected control point: 2*cur - lastCubicC2 if available, else cur.
                    if ($lastCubicC2x !== null && $lastCubicC2y !== null) {
                        $c1x = 2.0 * $cx - $lastCubicC2x;
                        $c1y = 2.0 * $cy - $lastCubicC2y;
                    } else {
                        $c1x = $cx;
                        $c1y = $cy;
                    }
                    [$c2x, $c2y] = self::pair($tokens, $i, $relative, $cx, $cy);
                    [$x, $y]     = self::pair($tokens, $i, $relative, $cx, $cy);
                    $cmds[] = new CubicBezier($c1x, $c1y, $c2x, $c2y, $x, $y);
                    $lastCubicC2x = $c2x;
                    $lastCubicC2y = $c2y;
                    $lastQuadCx = $lastQuadCy = null;
                    $cx = $x;
                    $cy = $y;
                    $lastCmd = $cmd;
                    break;

                case 'Q':
                    self::ensureNumbers($tokens, $i, 4, $cmd);
                    [$qcx, $qcy] = self::pair($tokens, $i, $relative, $cx, $cy);
                    [$x, $y]     = self::pair($tokens, $i, $relative, $cx, $cy);
                    $cmds[] = new QuadraticBezier($qcx, $qcy, $x, $y);
                    $lastQuadCx = $qcx;
                    $lastQuadCy = $qcy;
                    $lastCubicC2x = $lastCubicC2y = null;
                    $cx = $x;
                    $cy = $y;
                    $lastCmd = $cmd;
                    break;

                case 'T':
                    self::ensureNumbers($tokens, $i, 2, $cmd);
                    if ($lastQuadCx !== null && $lastQuadCy !== null) {
                        $qcx = 2.0 * $cx - $lastQuadCx;
                        $qcy = 2.0 * $cy - $lastQuadCy;
                    } else {
                        $qcx = $cx;
                        $qcy = $cy;
                    }
                    [$x, $y] = self::pair($tokens, $i, $relative, $cx, $cy);
                    $cmds[] = new QuadraticBezier($qcx, $qcy, $x, $y);
                    $lastQuadCx = $qcx;
                    $lastQuadCy = $qcy;
                    $lastCubicC2x = $lastCubicC2y = null;
                    $cx = $x;
                    $cy = $y;
                    $lastCmd = $cmd;
                    break;

                case 'A':
                    self::ensureNumbers($tokens, $i, 7, $cmd);
                    $rx = self::one($tokens, $i);
                    $ry = self::one($tokens, $i);
                    $xRot = self::one($tokens, $i);
                    $largeArc = self::one($tokens, $i) !== 0.0;
                    $sweep = self::one($tokens, $i) !== 0.0;
                    [$x, $y] = self::pair($tokens, $i, $relative, $cx, $cy);
                    $cmds[] = new Arc(abs($rx), abs($ry), $xRot, $largeArc, $sweep, $x, $y);
                    $cx = $x;
                    $cy = $y;
                    $lastCubicC2x = $lastCubicC2y = null;
                    $lastQuadCx = $lastQuadCy = null;
                    $lastCmd = $cmd;
                    break;

                case 'Z':
                    $cmds[] = new ClosePath();
                    $cx = $startX;
                    $cy = $startY;
                    $lastCubicC2x = $lastCubicC2y = null;
                    $lastQuadCx = $lastQuadCy = null;
                    $lastCmd = $cmd;
                    break;

                default:
                    // Unknown command -> truncate parse silently per spec.
                    return $cmds;
            }
        }

        return $cmds;
    }

    /**
     * @return list<string|float>
     */
    private static function tokenize(string $d): array
    {
        $tokens = [];
        $len = strlen($d);
        $i = 0;
        while ($i < $len) {
            $ch = $d[$i];
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r" || $ch === ',') {
                $i++;
                continue;
            }

            if (preg_match(self::COMMAND_RX, $d, $m, 0, $i) === 1) {
                $tokens[] = $m[0];
                $i += strlen($m[0]);
                continue;
            }

            if (preg_match(self::NUMBER_RX, $d, $m, 0, $i) === 1) {
                $tokens[] = (float) $m[0];
                $i += strlen($m[0]);
                continue;
            }

            // Unknown character (including unsupported command letters that did
            // not match -- they DID match, so this branch only fires on real
            // garbage). Stop tokenization here; the parser will truncate at
            // the next "default" branch.
            $tokens[] = $ch;
            $i++;
        }
        return $tokens;
    }

    /**
     * @param list<string|float> $tokens
     */
    private static function ensureNumbers(array $tokens, int $i, int $count, string $cmd): void
    {
        for ($j = 0; $j < $count; $j++) {
            if (!isset($tokens[$i + $j]) || !is_float($tokens[$i + $j])) {
                throw new PdfException(
                    'Path command ' . $cmd . ' expects ' . $count . ' numbers, got ' . $j,
                );
            }
        }
    }

    /**
     * @param list<string|float> $tokens
     * @return array{0: float, 1: float}
     */
    private static function pair(array $tokens, int &$i, bool $relative, float $cx, float $cy): array
    {
        /** @var float $x */
        $x = $tokens[$i++];
        /** @var float $y */
        $y = $tokens[$i++];
        return $relative ? [$cx + $x, $cy + $y] : [$x, $y];
    }

    /**
     * @param list<string|float> $tokens
     */
    private static function one(array $tokens, int &$i): float
    {
        /** @var float $v */
        $v = $tokens[$i++];
        return $v;
    }
}
