<?php

declare(strict_types=1);

namespace YetiPdf\Font;

/**
 * A ToUnicode CMap: character code => text.
 *
 * The parser takes whatever it can read and ignores the rest. A malformed range or a missing
 * codespace section costs the mappings in that section, never the whole font.
 */
final class CMap implements CodeMap
{
    private const LAZY_RANGE = 512;

    /** @var array<int, string> */
    public array $map = [];
    /** @var list<array{0: int, 1: int, 2: string, 3: int}> [low, high, text before the last character, last code point] */
    private array $ranges = [];
    /** @var array<int, true> code lengths in bytes that the CMap declares or uses */
    public array $lengths = [];
    /** @var list<array{0: int, 1: int, 2: int}> [bytes, low, high] */
    public array $codespaces = [];

    public static function parse(string $data): self
    {
        $cmap = new self();

        if (preg_match_all('/begincodespacerange(.*?)(?:endcodespacerange|$)/s', $data, $sections)) {
            foreach ($sections[1] as $section) {
                preg_match_all('/<([0-9A-Fa-f\s]*)>\s*<([0-9A-Fa-f\s]*)>/', $section, $pairs, PREG_SET_ORDER);
                foreach ($pairs as $pair) {
                    $lo = self::clean($pair[1]);
                    $hi = self::clean($pair[2]);
                    $bytes = intdiv(strlen($lo) + 1, 2);
                    if ($bytes >= 1 && $bytes <= 4) {
                        $cmap->lengths[$bytes] = true;
                        $cmap->codespaces[] = [$bytes, (int)hexdec($lo), (int)hexdec($hi)];
                    }
                }
            }
        }
        $declared = $cmap->lengths !== [];

        if (preg_match_all('/beginbfchar(.*?)(?:endbfchar|$)/s', $data, $sections)) {
            foreach ($sections[1] as $section) {
                preg_match_all('/<([0-9A-Fa-f\s]*)>\s*<([0-9A-Fa-f\s]*)>/', $section, $pairs, PREG_SET_ORDER);
                foreach ($pairs as $pair) {
                    $src = self::clean($pair[1]);
                    if ($src === '' || strlen($src) > 8) {
                        continue;
                    }
                    if (!$declared) {
                        $cmap->lengths[intdiv(strlen($src) + 1, 2)] = true;
                    }
                    $cmap->map[(int)hexdec($src)] = self::text($pair[2]);
                }
            }
        }

        if (preg_match_all('/beginbfrange(.*?)(?:endbfrange|$)/s', $data, $sections)) {
            foreach ($sections[1] as $section) {
                preg_match_all('/<([0-9A-Fa-f\s]*)>|(\[)|(\])/', $section, $tokens, PREG_SET_ORDER);
                $n = count($tokens);
                $i = 0;
                while ($i + 2 < $n) {
                    if (($tokens[$i][2] ?? '') !== '' || ($tokens[$i][3] ?? '') !== '') {
                        $i++;
                        continue;
                    }
                    $loHex = self::clean($tokens[$i][1]);
                    $hiHex = self::clean($tokens[$i + 1][1] ?? '');
                    $lo = (int)hexdec($loHex);
                    $hi = (int)hexdec($hiHex);
                    $i += 2;
                    if (!$declared && $loHex !== '') {
                        $cmap->lengths[intdiv(strlen($loHex) + 1, 2)] = true;
                    }

                    if (($tokens[$i][2] ?? '') === '[') {
                        $i++;
                        $code = $lo;
                        while ($i < $n && ($tokens[$i][3] ?? '') !== ']') {
                            if (($tokens[$i][2] ?? '') === '' && $code <= $hi) {
                                $cmap->map[$code] = self::text($tokens[$i][1]);
                            }
                            $code++;
                            $i++;
                        }
                        $i++;
                        continue;
                    }

                    $dst = Utf::utf16Codepoints(self::bytes($tokens[$i][1] ?? ''));
                    $i++;
                    if ($dst === [] || $hi < $lo || strlen($loHex) > 8) {
                        continue;
                    }
                    $last = array_pop($dst);
                    $prefix = '';
                    foreach ($dst as $cp) {
                        $prefix .= Utf::chr($cp);
                    }
                    if ($hi - $lo > self::LAZY_RANGE) {
                        $cmap->ranges[] = [$lo, $hi, $prefix, $last];
                        continue;
                    }
                    for ($code = $lo; $code <= $hi; $code++) {
                        $cmap->map[$code] = $prefix . Utf::chr($last + $code - $lo);
                    }
                }
            }
        }

        return $cmap;
    }

    public function get(int $code): ?string
    {
        if (isset($this->map[$code])) {
            return $this->map[$code];
        }
        foreach ($this->ranges as [$lo, $hi, $prefix, $last]) {
            if ($code >= $lo && $code <= $hi) {
                return $this->map[$code] = $prefix . Utf::chr($last + $code - $lo);
            }
        }
        return null;
    }

    public function isEmpty(): bool
    {
        return $this->map === [] && $this->ranges === [];
    }

    private static function clean(string $hex): string
    {
        return ctype_xdigit($hex) ? $hex : (preg_replace('/[^0-9A-Fa-f]+/', '', $hex) ?? '');
    }

    private static function bytes(string $hex): string
    {
        $hex = self::clean($hex);
        if (strlen($hex) & 1) {
            $hex = '0' . $hex;
        }
        return $hex === '' ? '' : (string)hex2bin($hex);
    }

    private static function text(string $hex): string
    {
        $text = Utf::utf16(self::bytes($hex));
        // U+0000 and U+FFFD are how broken generators say "no idea".
        return ($text === "\0" || $text === "\u{FFFD}") ? '' : $text;
    }
}
