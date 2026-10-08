<?php

declare(strict_types=1);

namespace YetiPdf\Content;

use YetiPdf\Font\Utf;

/**
 * Puts a line with Hebrew or Arabic in it into reading order.
 *
 * A PDF draws every line from left to right, whatever the script, so right-to-left text arrives
 * with its last letter first. This is the small part of the Unicode bidirectional algorithm that
 * search needs: right-to-left words are turned around, and the numbers and Latin words among them
 * are kept as they are.
 */
final class Bidi
{
    private const RIGHT = 1;
    private const NUMBER = 2;
    private const LEFT = 3;
    private const OTHER = 4;
    private const LONGEST_LINE = 16384;

    /** Right-to-left letters and marks, without the two sets of Arabic digits: those read left to right. */
    private const R = '\x{0590}-\x{065F}\x{066A}-\x{06EF}\x{06FA}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFC}';
    private const DIGITS = '0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}';

    /** A run of right-to-left letters, a run of digits, a word in any other script, or one character of anything else. */
    private const TOKENS = '/([' . self::R . ']+)|([' . self::DIGITS . ']+)|((?:(?![' . self::R . '])[\p{L}\p{M}])+)|(.)/us';

    /** @var array<string, string>|null */
    private static ?array $forms = null;

    /** Turns a line in the order it is drawn, left to right, into the order it is read. */
    public static function logical(string $line): string
    {
        // Each word and each punctuation mark costs a small array below. A line of text is a few hundred
        // bytes; something far longer is not a line anybody will read, and is left as it is.
        if (strlen($line) > self::LONGEST_LINE || !preg_match_all(self::TOKENS, $line, $m, PREG_SET_ORDER)) {
            return $line;
        }
        $tokens = [];
        $right = 0;
        $left = 0;
        $ends = [];
        foreach ($m as $t) {
            if ($t[1] !== '') {
                $tokens[] = [self::RIGHT, $t[1]];
                $right += self::length($t[1]);
                $ends[] = self::RIGHT;
            } elseif ($t[2] !== '') {
                $tokens[] = [self::NUMBER, $t[2]];
            } elseif ($t[3] !== '') {
                $tokens[] = [self::LEFT, $t[3]];
                $left += self::length($t[3]);
                $ends[] = self::LEFT;
            } else {
                $tokens[] = [self::OTHER, $t[4]];
            }
        }
        if ($right === 0) {
            return $line;
        }

        // Which way does the line as a whole read? A line with the same kind of word at both ends reads
        // that way. Otherwise the page cannot tell us, and the script with more letters wins.
        $first = $ends[0];
        $last = $ends[count($ends) - 1];
        if ($first === $last ? $first === self::RIGHT : $right >= $left) {
            return self::plain(self::turn($tokens));
        }

        // A left-to-right line with right-to-left phrases in it: turn each phrase around where it stands.
        $out = '';
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i][0] !== self::RIGHT) {
                $out .= $tokens[$i][1];
                continue;
            }
            $last = $i;
            for ($k = $i + 1; $k < $n && $tokens[$k][0] !== self::LEFT; $k++) {
                if ($tokens[$k][0] === self::RIGHT) {
                    $last = $k;
                }
            }
            $out .= self::turn(array_slice($tokens, $i, $last - $i + 1));
            $i = $last;
        }
        return self::plain($out);
    }

    /**
     * Reverses a stretch of right-to-left text. Whatever reads left to right inside it is first
     * gathered into single pieces, so that "Hello World" and "12.5" survive the reversal.
     *
     * @param list<array{0: int, 1: string}> $tokens
     */
    private static function turn(array $tokens): string
    {
        $pieces = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            [$class, $text] = $tokens[$i];
            if ($class !== self::LEFT && $class !== self::NUMBER) {
                $pieces[] = $class === self::RIGHT ? Utf::reverse($text) : $text;
                continue;
            }
            // Words join across spaces and punctuation, and take the numbers after them along.
            // Numbers on their own join only across a separator inside them, or straight into a word ("3D").
            $word = $class === self::LEFT;
            while (true) {
                $between = '';
                for ($k = $i + 1; $k < $n && $tokens[$k][0] === self::OTHER; $k++) {
                    $between .= $tokens[$k][1];
                }
                $next = $tokens[$k][0] ?? 0;
                if ($next === self::LEFT ? ($word || $between === '') : ($next === self::NUMBER && ($word || self::separator($between)))) {
                    $text .= $between . $tokens[$k][1];
                    $word = $word || $next === self::LEFT;
                    $i = $k;
                    continue;
                }
                break;
            }
            $pieces[] = $text;
        }
        $out = implode('', array_reverse($pieces));

        // Some programs name a bracket by the way it faces on the page, which is the wrong way round in
        // right-to-left text. If the first bracket of the stretch is a closing one, they all are.
        if (preg_match('/[()\[\]{}]/', $out, $first) && str_contains(')]}', $first[0])) {
            $out = strtr($out, '()[]{}', ')(][}{');
        }
        return $out;
    }

    private static function separator(string $between): bool
    {
        return strlen($between) === 1 && str_contains('.,:/-+', $between);
    }

    /** Characters, not bytes. */
    private static function length(string $text): int
    {
        return (int)preg_match_all('/[^\x80-\xBF]/', $text);
    }

    /**
     * Swaps presentation forms for ordinary letters. A PDF often names an Arabic letter by the form
     * it takes at that spot in the word (first, middle, last, alone), which nobody types in a search box.
     */
    private static function plain(string $text): string
    {
        // Every presentation form starts with this byte in UTF-8.
        if (!str_contains($text, "\xEF")) {
            return $text;
        }
        self::$forms ??= require dirname(__DIR__, 2) . '/data/presentationforms.php';
        return strtr($text, self::$forms);
    }
}
