<?php

declare(strict_types=1);

namespace YetiPdf\Core;

/**
 * Parses PDF objects out of a byte string.
 *
 * Values come back as native PHP types: null, bool, int, float, a list for an array, a string-keyed
 * array for a dictionary (keys without the leading slash), a string starting with "/" for a name,
 * and PdfString / Ref / Stream instances for the rest.
 */
final class Lexer
{
    public const WS = "\0\t\n\f\r ";
    private const DELIM = "\0\t\n\f\r ()<>[]{}/%";
    private const MAX_DEPTH = 100;

    public static function skip(string $d, int &$p): void
    {
        $len = strlen($d);
        while ($p < $len) {
            $p += strspn($d, self::WS, $p);
            if ($p < $len && $d[$p] === '%') {
                $p += strcspn($d, "\r\n", $p);
                continue;
            }
            return;
        }
    }

    /**
     * Parse "N G obj <value>" at $p.
     *
     * @return array{0: int, 1: int, 2: mixed}|null object number, generation, value
     */
    public static function indirect(string $d, int $p): ?array
    {
        if ($p < 0 || $p >= strlen($d)
            || !preg_match('/\G[\0\t\n\f\r ]*(\d{1,10})[\0\t\n\f\r ]+(\d{1,10})[\0\t\n\f\r ]*obj/', $d, $m, 0, $p)) {
            return null;
        }
        $p += strlen($m[0]);
        $num = (int)$m[1];
        $gen = (int)$m[2];
        $value = self::value($d, $p);

        if (is_array($value)) {
            self::skip($d, $p);
            if ($p + 6 <= strlen($d) && substr_compare($d, 'stream', $p, 6) === 0) {
                $p += 6;
                if (($d[$p] ?? '') === "\r") {
                    $p++;
                }
                if (($d[$p] ?? '') === "\n") {
                    $p++;
                }
                $value = new Stream($value, $p, $num, $gen);
            }
        }

        return [$num, $gen, $value];
    }

    public static function value(string $d, int &$p, int $depth = 0): mixed
    {
        self::skip($d, $p);
        $len = strlen($d);
        if ($p >= $len || $depth > self::MAX_DEPTH) {
            return null;
        }

        $c = $d[$p];

        if ($c === '/') {
            $n = strcspn($d, self::DELIM, $p + 1);
            $name = substr($d, $p, $n + 1);
            $p += $n + 1;
            return str_contains($name, '#') ? self::unescapeName($name) : $name;
        }

        if ($c >= '0' && $c <= '9') {
            if (preg_match('/\G(\d{1,10})[\0\t\n\f\r ]+(\d{1,10})[\0\t\n\f\r ]+R(?![A-Za-z0-9])/', $d, $m, 0, $p)) {
                $p += strlen($m[0]);
                return new Ref((int)$m[1], (int)$m[2]);
            }
            return self::number($d, $p);
        }

        if ($c === '-' || $c === '+' || $c === '.') {
            return self::number($d, $p);
        }

        if ($c === '<') {
            if (($d[$p + 1] ?? '') === '<') {
                return self::dict($d, $p, $depth);
            }
            $end = strpos($d, '>', $p);
            if ($end === false) {
                $end = $len;
            }
            $hex = substr($d, $p + 1, $end - $p - 1);
            $p = min($len, $end + 1);
            return new PdfString(self::hex($hex));
        }

        if ($c === '(') {
            return new PdfString(self::literal($d, $p));
        }

        if ($c === '[') {
            $p++;
            $list = [];
            while (true) {
                self::skip($d, $p);
                if ($p >= $len) {
                    break;
                }
                if ($d[$p] === ']') {
                    $p++;
                    break;
                }
                $before = $p;
                $list[] = self::value($d, $p, $depth + 1);
                if ($p === $before) {
                    $p++;
                    array_pop($list);
                }
            }
            return $list;
        }

        // Keywords: true, false, null, or something we don't understand.
        $n = strcspn($d, self::DELIM, $p);
        if ($n === 0) {
            // A stray delimiter such as ")" or ">"; the caller decides how to get past it.
            return null;
        }
        $word = substr($d, $p, $n);
        $p += $n;
        return match ($word) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private static function dict(string $d, int &$p, int $depth): array
    {
        $p += 2;
        $len = strlen($d);
        $dict = [];
        while (true) {
            self::skip($d, $p);
            if ($p >= $len) {
                break;
            }
            $c = $d[$p];
            if ($c === '>') {
                $p += (($d[$p + 1] ?? '') === '>') ? 2 : 1;
                break;
            }
            if ($c !== '/') {
                // Not a key. Stop at keywords that mean the dictionary was never closed,
                // otherwise step over whatever this is and keep going.
                if (preg_match('/\G(?:endobj|stream|endstream|xref|trailer)\b/', $d, $m, 0, $p)) {
                    break;
                }
                $before = $p;
                self::value($d, $p, $depth + 1);
                if ($p === $before) {
                    $p++;
                }
                continue;
            }
            $n = strcspn($d, self::DELIM, $p + 1);
            $key = substr($d, $p + 1, $n);
            $p += $n + 1;
            if (str_contains($key, '#')) {
                $key = substr(self::unescapeName('/' . $key), 1);
            }
            $dict[$key] = self::value($d, $p, $depth + 1);
        }
        return $dict;
    }

    private static function number(string $d, int &$p): int|float
    {
        $n = strspn($d, '+-.0123456789', $p);
        $tok = substr($d, $p, $n);
        $p += max(1, $n);
        if (!str_contains($tok, '.') && strlen($tok) < 18) {
            return (int)$tok;
        }
        return (float)$tok;
    }

    private static function unescapeName(string $name): string
    {
        return preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn($m) => chr((int)hexdec($m[1])), $name) ?? $name;
    }

    public static function hex(string $hex): string
    {
        if (!ctype_xdigit($hex)) {
            $hex = preg_replace('/[^0-9A-Fa-f]+/', '', $hex) ?? '';
        }
        if (strlen($hex) & 1) {
            $hex .= '0';
        }
        return $hex === '' ? '' : (string)hex2bin($hex);
    }

    /** Reads a "(...)" string at $p, handling nested parentheses and escapes. */
    private static function literal(string $d, int &$p): string
    {
        $len = strlen($d);
        $i = $p + 1;
        $depth = 1;
        $escaped = false;
        while ($i < $len) {
            $i += strcspn($d, '()\\', $i);
            if ($i >= $len) {
                break;
            }
            $c = $d[$i];
            if ($c === '\\') {
                $escaped = true;
                $i += 2;
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif (--$depth === 0) {
                break;
            }
            $i++;
        }
        $raw = substr($d, $p + 1, max(0, $i - $p - 1));
        $p = min($len, $i + 1);
        return $escaped ? self::unescape($raw) : $raw;
    }

    /** Resolves backslash escapes inside a literal string body. */
    public static function unescape(string $s): string
    {
        return preg_replace_callback(
            '/\\\\(?:([0-7]{1,3})|(\r\n|\r|\n)|([\s\S]))/',
            static function (array $m): string {
                if ($m[1] !== '') {
                    return chr(octdec($m[1]) & 0xFF);
                }
                if ($m[2] !== '') {
                    return '';
                }
                return match ($m[3]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\x08",
                    'f' => "\f",
                    default => $m[3],
                };
            },
            $s
        ) ?? $s;
    }
}
