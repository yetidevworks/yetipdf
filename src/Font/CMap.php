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
    /** Ranges wider than this are looked up when asked for, not expanded. */
    private const LAZY_RANGE = 512;
    /**
     * Bytes ranges may take when expanded: a flat amount, which no real CMap comes near (65,536 two-byte codes
     * cost about 4MB), plus a multiple of the CMap's own size. A range past that is looked up instead.
     */
    private const EXPAND_FLOOR = 8 << 20;
    private const EXPAND_PER_BYTE = 64;
    /** Most bytes of UTF-16 a code may map to. Real mappings are a few characters; a ligature is three or four, a joined emoji up to about ten. */
    private const MAX_TARGET = 256;
    /** A code and what it stands for, or the two ends of a range of codes. */
    private const PAIR = '/<([0-9A-Fa-f\s]*)>\s*<([0-9A-Fa-f\s]*)>/';

    /** @var array<int, string> */
    public array $map = [];
    /** True when a section was left unread because it was too big for the memory that is left. */
    public bool $partial = false;
    /** @var list<array{0: int, 1: int, 2: string, 3: int}> [low, high, text before the last character, last code point] */
    private array $ranges = [];
    private ?Ranges $index = null;
    /** @var array<int, true> code lengths in bytes that the CMap declares or uses */
    public array $lengths = [];
    /** @var list<array{0: int, 1: int, 2: int}> [bytes, low, high] */
    public array $codespaces = [];

    /** @param int $room bytes of memory this map may work in; a section that would need more is not read */
    public static function parse(string $data, int $room = PHP_INT_MAX): self
    {
        $cmap = new self();
        // What ranges may take when written out, and never more than a quarter of what is left.
        $expandable = min(max(self::EXPAND_FLOOR, self::EXPAND_PER_BYTE * strlen($data)), intdiv($room, 4));
        // Reading a section costs about fifteen bytes for each byte of it, and up to thirty for one written to cost the most.
        $largest = intdiv($room, 64);
        // All the sections together get twice that: what is kept from each costs several times its size as well.
        $allowed = intdiv($room, 32);
        $seen = [];

        foreach (self::sections($data, 'begincodespacerange', 'endcodespacerange') as $section) {
            $size = strlen($section);
            if ($size > $largest || $size > $allowed || preg_match_all(self::PAIR, $section, $pairs) === false) {
                $cmap->partial = true;
                continue;
            }
            $allowed -= $size;
            foreach ($pairs[1] as $i => $first) {
                $lo = self::clean($first);
                $hi = self::clean($pairs[2][$i]);
                $bytes = intdiv(strlen($lo) + 1, 2);
                // The same range a million times over is one range, and a few thousand different ones is past any real map.
                if ($bytes >= 1 && $bytes <= 4 && !isset($seen["$lo-$hi"]) && count($seen) < 4096) {
                    $seen["$lo-$hi"] = true;
                    $cmap->lengths[$bytes] = true;
                    $cmap->codespaces[] = [$bytes, (int)hexdec($lo), (int)hexdec($hi)];
                }
            }
        }
        unset($seen);
        $cmap->codespaces = self::joined($cmap->codespaces);
        $declared = $cmap->lengths !== [];

        foreach (self::sections($data, 'beginbfchar', 'endbfchar') as $section) {
            $size = strlen($section);
            if ($size > $largest || $size > $allowed || preg_match_all(self::PAIR, $section, $pairs) === false) {
                $cmap->partial = true;
                continue;
            }
            $allowed -= $size;
            foreach ($pairs[1] as $i => $first) {
                $src = self::clean($first);
                if ($src === '' || strlen($src) > 8) {
                    continue;
                }
                if (!$declared) {
                    $cmap->lengths[intdiv(strlen($src) + 1, 2)] = true;
                }
                $cmap->map[(int)hexdec($src)] = self::text($pairs[2][$i]);
            }
        }

        foreach (self::sections($data, 'beginbfrange', 'endbfrange') as $section) {
            // Each token is "<hex>", "[" or "]". One flat list of them is a tenth of the memory that a list of matches with groups takes.
            $size = strlen($section);
            if ($size > $largest || $size > $allowed || preg_match_all('/<[0-9A-Fa-f\s]*>|[\[\]]/', $section, $found) === false) {
                $cmap->partial = true;
                continue;
            }
            $allowed -= $size;
            $tokens = $found[0];
            unset($found);
            $n = count($tokens);
            $i = 0;
            while ($i + 2 < $n) {
                if ($tokens[$i][0] !== '<') {
                    $i++;
                    continue;
                }
                $loHex = self::clean(substr($tokens[$i], 1, -1));
                $hiHex = $tokens[$i + 1][0] === '<' ? self::clean(substr($tokens[$i + 1], 1, -1)) : '';
                $lo = (int)hexdec($loHex);
                $hi = (int)hexdec($hiHex);
                $i += 2;
                if (!$declared && $loHex !== '') {
                    $cmap->lengths[intdiv(strlen($loHex) + 1, 2)] = true;
                }

                if ($tokens[$i] === '[') {
                    $i++;
                    $code = $lo;
                    while ($i < $n && $tokens[$i] !== ']') {
                        if ($tokens[$i] !== '[' && $code <= $hi) {
                            $cmap->map[$code] = self::text(substr($tokens[$i], 1, -1));
                        }
                        $code++;
                        $i++;
                    }
                    $i++;
                    continue;
                }

                $target = $tokens[$i][0] === '<' ? self::bytes(substr($tokens[$i], 1, -1)) : '';
                $i++;
                if ($target === '' || strlen($target) > self::MAX_TARGET || $hi < $lo || strlen($loHex) > 8) {
                    continue;
                }
                $dst = Utf::utf16Codepoints($target);
                $last = array_pop($dst);
                $prefix = '';
                foreach ($dst as $cp) {
                    $prefix .= Utf::chr($cp);
                }
                $cost = ($hi - $lo + 1) * (64 + strlen($prefix));
                if ($hi - $lo > self::LAZY_RANGE || $cost > $expandable) {
                    $cmap->ranges[] = [$lo, $hi, $prefix, $last];
                    continue;
                }
                $expandable -= $cost;
                for ($code = $lo; $code <= $hi; $code++) {
                    $cmap->map[$code] = $prefix . Utf::chr($last + $code - $lo);
                }
            }
        }

        unset($tokens);
        if ($cmap->ranges !== []) {
            $cmap->index = new Ranges($cmap->ranges);
        }
        return $cmap;
    }

    public function get(int $code): ?string
    {
        if (isset($this->map[$code])) {
            return $this->map[$code];
        }
        $range = $this->index?->find($code);
        if ($range === null) {
            return null;
        }
        [$lo, , $prefix, $last] = $this->ranges[$range];
        return $prefix . Utf::chr($last + $code - $lo);
    }

    /**
     * Code space ranges with those of one length that overlap or touch made into one, and no more than 16 of
     * them. A real map has a handful, and every code that is read is checked against each.
     *
     * @param list<array{0: int, 1: int, 2: int}> $spaces
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private static function joined(array $spaces): array
    {
        if (!isset($spaces[1])) {
            return $spaces;
        }
        usort($spaces, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $out = [];
        foreach ($spaces as $space) {
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last][0] === $space[0] && $space[1] <= $out[$last][2] + 1) {
                if ($space[2] > $out[$last][2]) {
                    $out[$last][2] = $space[2];
                }
                continue;
            }
            $out[] = $space;
        }
        return array_slice($out, 0, 16);
    }

    /**
     * The text between each $begin and the $end after it, or the end of the data when there is none.
     * A plain search: a pattern that does this gives up without a word on a section of a megabyte or so.
     *
     * @return list<string>
     */
    private static function sections(string $data, string $begin, string $end): array
    {
        $sections = [];
        for ($at = 0; ($from = strpos($data, $begin, $at)) !== false; $at = $to + strlen($end)) {
            $from += strlen($begin);
            $to = strpos($data, $end, $from);
            if ($to === false) {
                $sections[] = substr($data, $from);
                break;
            }
            $sections[] = substr($data, $from, $to - $from);
        }
        return $sections;
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
        $bytes = self::bytes($hex);
        if (strlen($bytes) > self::MAX_TARGET) {
            return '';
        }
        $text = Utf::utf16($bytes);
        // U+0000 and U+FFFD are how broken generators say "no idea".
        return ($text === "\0" || $text === "\u{FFFD}") ? '' : $text;
    }
}
