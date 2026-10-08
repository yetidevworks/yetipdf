<?php

declare(strict_types=1);

namespace YetiPdf\Font;

/**
 * Everything the content interpreter needs from a font: how to turn the bytes of a shown string
 * into text, and how far that string moves the text position.
 *
 * decode() returns the text and leaves the measurements in $w, $n and $sp, which avoids
 * building a result array for every string on the page.
 */
final class Font
{
    private const MEMO_LIMIT = 1000;
    /** Stands in for a glyph that has a width but no known character. It reaches the page text as a space. */
    public const GAP = "\x1F";
    /** Width, in text units, from which such a glyph counts as a gap between words. */
    public const GAP_WIDTH = 0.1;
    /**
     * Bytes of text the per-code cache may hold; a character map can make one code stand for a lot of text.
     * (The string cache needs no such limit: it holds a thousand strings of 48 bytes, and a code is at most 384 bytes of text.)
     */
    private const CACHE_BYTES = 8 << 20;
    /** Strings longer than this are decoded in pieces of this many bytes (an even number, so no two-byte code is split). */
    private const PIECE = 16384;

    /** Single-byte codes (Type1, TrueType, Type3) or multi-byte (Type0). */
    public bool $simple = true;
    /** Simple fonts: byte => replacement text for strtr(); omitted bytes are unchanged. */
    public array $map = [];
    /** Code (simple) or CID (composite) => glyph width in glyph units. */
    public array $widths = [];
    public float $defaultWidth = 500.0;
    /**
     * Composite fonts whose /W has ranges too wide to write out hold all their widths here instead of in $widths:
     * the ranges, flattened so that later entries win, and the width of each one.
     */
    public ?Ranges $widthIndex = null;
    /** @var list<float> */
    public array $rangeWidths = [];
    /** Glyph units to text units: 0.001 for everything except Type3 fonts. */
    public float $scale = 0.001;

    /** Composite fonts only. */
    public int $codeBytes = 2;
    public ?CodeMap $toUnicode = null;
    /** Codes are UTF-16BE already (the UniXXX-UCS2/UTF16 predefined CMaps). */
    public bool $codesAreUnicode = false;
    /** A legacy multi-byte charset name for mb_convert_encoding, when the encoding is one of the old CJK CMaps. */
    public ?string $charset = null;
    /** @var list<array{0: int, 1: int, 2: int}> [bytes, low, high] when codes have mixed lengths */
    public array $codespaces = [];
    /** True when the font gave no way to map codes to text. */
    public bool $unmapped = false;
    /** True once any code has been mapped to GAP. */
    public bool $gaps = false;
    /** True once the font is known to hold right-to-left letters. Lines set in it are put into reading order. */
    public bool $rtl = false;
    private bool $rtlKnown = false;
    /** Decoding a string stops once its text is this long, because a character map can turn each code into many characters. */
    public int $maxText = 64 << 20;
    /** Called with a plain-English message when decoding cuts something short. */
    public ?\Closure $warn = null;

    /** Width of the last decoded string in text units (before font size is applied). */
    public float $w = 0.0;
    /** Number of glyphs in the last decoded string. */
    public int $n = 0;
    /** Number of single-byte 0x20 codes in the last decoded string (word spacing applies to these). */
    public int $sp = 0;

    /** @var array<string, array{0: string, 1: float, 2: int, 3: int}> */
    private array $memo = [];
    /** @var array<int, string> composite code => text */
    private array $codeText = [];
    /** @var array<int|string, string>|null byte (simple) or code (composite) => text as drawn() gives it */
    private ?array $turned = null;
    private int $codeTextBytes = 0;
    /** @var array<int, float> composite code => width, for fonts with $widthIndex */
    private array $widthCache = [];

    public function decode(string $s): string
    {
        if (isset($this->memo[$s])) {
            [$text, $this->w, $this->n, $this->sp] = $this->memo[$s];
            return $text;
        }

        if ($this->simple) {
            $w = 0.0;
            $sp = 0;
            foreach (count_chars($s, 1) as $code => $count) {
                $w += ($this->widths[$code] ?? $this->defaultWidth) * $count;
                if ($code === 32) {
                    $sp = $count;
                }
            }
            $this->w = $w * $this->scale;
            $this->n = strlen($s);
            $this->sp = $sp;
            $text = strtr($s, $this->map);
            if (!$this->rtlKnown) {
                $this->rtlKnown = true;
                $this->rtl = (bool)preg_match(Utf::RIGHT_TO_LEFT, implode('', $this->map));
            }
        } elseif ($this->charset !== null) {
            $text = (string)@mb_convert_encoding($s, 'UTF-8', $this->charset);
            $this->n = mb_strlen($text, 'UTF-8');
            $this->w = $this->n * $this->defaultWidth * $this->scale;
            $this->sp = 0;
        } elseif (isset($s[self::PIECE]) && $this->codespaces === []) {
            $text = $this->decodeInPieces($s);
        } else {
            $text = $this->decodeComposite($s);
        }

        // A string of nothing but gaps is left to the positions on the page, which already tell words apart.
        if ($this->gaps && trim($text, self::GAP) === '') {
            $text = '';
        }

        if (strlen($s) <= 48) {
            if (count($this->memo) >= self::MEMO_LIMIT) {
                $this->memo = [];
            }
            $this->memo[$s] = [$text, $this->w, $this->n, $this->sp];
        }
        return $text;
    }

    /**
     * The text of a string as decode() gives it, except that a glyph standing for several right-to-left
     * characters has them last one first.
     *
     * Right-to-left text is drawn glyph by glyph from the left, so the caller turns the whole line around
     * to read it. A ligature's own characters are already in reading order ("lam" then "alef" for the
     * one glyph that joins them), and turning them around here means they come out right after that.
     */
    public function drawn(string $s): string
    {
        if ($this->simple) {
            $this->turned ??= array_map(self::turn(...), $this->map);
            return strtr($s, $this->turned);
        }
        if ($this->charset !== null) {
            return $this->decode($s);
        }
        $out = '';
        if ($this->codespaces !== []) {
            for ($i = 0, $n = 0, $len = strlen($s); $i < $len; $i += $bytes) {
                [$bytes, $code] = $this->codeAt($s, $i, $len);
                $out .= $this->turned[$code] ??= self::turn($this->codeText[$code] ?? $this->lookup($code));
                if ((++$n & 0xFFF) === 0 && isset($out[$this->maxText])) {
                    break;
                }
            }
            return $out;
        }
        // In pieces, like decode(), and stopping where it stops.
        foreach (isset($s[self::PIECE]) ? str_split($s, self::PIECE) : [$s] as $piece) {
            $codes = $this->codeBytes === 1 ? unpack('C*', $piece) : unpack('n*', strlen($piece) & 1 ? $piece . "\0" : $piece);
            foreach ($codes ?: [] as $code) {
                $out .= $this->turned[$code] ??= self::turn($this->codeText[$code] ?? $this->lookup($code));
            }
            if (isset($out[$this->maxText])) {
                break;
            }
        }
        return $out;
    }

    /** The text of one glyph, last character first when it has several and they read right to left. */
    private static function turn(string $unit): string
    {
        // One character has nothing to turn around, and the length of a character shows in its first byte.
        $first = $unit[0] ?? '';
        if (isset($unit[$first < "\xE0" ? 2 : ($first < "\xF0" ? 3 : 4)]) && preg_match(Utf::RIGHT_TO_LEFT, $unit)) {
            return Utf::reverse($unit);
        }
        return $unit;
    }

    /**
     * The code that starts at byte $i of a string, for a font whose codes vary in length.
     *
     * @return array{0: int, 1: int} bytes taken, code
     */
    private function codeAt(string $s, int $i, int $len): array
    {
        $value = 0;
        for ($bytes = 1; $bytes <= 4 && $i + $bytes <= $len; $bytes++) {
            $value = ($value << 8) | ord($s[$i + $bytes - 1]);
            foreach ($this->codespaces as [$b, $lo, $hi]) {
                if ($b === $bytes && $value >= $lo && $value <= $hi) {
                    return [$bytes, $value];
                }
            }
        }
        return [1, ord($s[$i])];
    }

    /** A string of fixed-width codes too long to unpack in one go: that takes sixteen bytes of array for every byte of string. */
    private function decodeInPieces(string $s): string
    {
        $text = '';
        $w = 0.0;
        $n = 0;
        $sp = 0;
        foreach (str_split($s, self::PIECE) as $piece) {
            $text .= $this->decodeComposite($piece);
            $w += $this->w;
            $n += $this->n;
            $sp += $this->sp;
            if (isset($text[$this->maxText])) {
                $this->tooLong();
                break;
            }
        }
        $this->w = $w;
        $this->n = $n;
        $this->sp = $sp;
        return $text;
    }

    private function decodeComposite(string $s): string
    {
        $text = '';
        $w = 0.0;
        $n = 0;
        $sp = 0;
        $widths = $this->widths;
        $dw = $this->defaultWidth;
        $ranged = $this->widthIndex !== null;

        if ($this->codespaces === []) {
            $codes = $this->codeBytes === 1 ? unpack('C*', $s) : unpack('n*', strlen($s) & 1 ? $s . "\0" : $s);
            foreach ($codes ?: [] as $code) {
                $text .= $this->codeText[$code] ?? $this->lookup($code);
                $w += $widths[$code] ?? ($ranged ? $this->rangeWidth($code) : $dw);
                $n++;
            }
            if ($this->codeBytes === 1) {
                $sp = substr_count($s, ' ');
            }
        } else {
            $len = strlen($s);
            $i = 0;
            while ($i < $len) {
                $code = 0;
                $matched = 0;
                $value = 0;
                for ($bytes = 1; $bytes <= 4 && $i + $bytes <= $len; $bytes++) {
                    $value = ($value << 8) | ord($s[$i + $bytes - 1]);
                    foreach ($this->codespaces as [$b, $lo, $hi]) {
                        if ($b === $bytes && $value >= $lo && $value <= $hi) {
                            $matched = $bytes;
                            $code = $value;
                            break 2;
                        }
                    }
                }
                if ($matched === 0) {
                    $matched = 1;
                    $code = ord($s[$i]);
                }
                if ($matched === 1 && $code === 32) {
                    $sp++;
                }
                $text .= $this->codeText[$code] ?? $this->lookup($code);
                $w += $widths[$code] ?? ($ranged ? $this->rangeWidth($code) : $dw);
                $n++;
                $i += $matched;
                if (($n & 0xFFF) === 0 && isset($text[$this->maxText])) {
                    $this->tooLong();
                    break;
                }
            }
        }

        $this->w = $w * $this->scale;
        $this->n = $n;
        $this->sp = $sp;
        return $text;
    }

    private function tooLong(): void
    {
        if ($this->warn !== null) {
            ($this->warn)('A string shown in a font turns into more text than the memory that is left allows; the rest of it was left out');
        }
    }

    private function rangeWidth(int $code): float
    {
        if (isset($this->widthCache[$code])) {
            return $this->widthCache[$code];
        }
        $range = $this->widthIndex?->find($code);
        if (count($this->widthCache) >= 65536) {
            $this->widthCache = [];
        }
        return $this->widthCache[$code] = $range === null ? $this->defaultWidth : $this->rangeWidths[$range];
    }

    private function lookup(int $code): string
    {
        $text = $this->toUnicode?->get($code);
        if ($text === null) {
            if ($this->codesAreUnicode) {
                $text = ($code >= 0xD800 && $code <= 0xDFFF) ? '' : Utf::chr($code);
            } elseif (!$this->unmapped && ($this->widths[$code] ?? 0.0) * $this->scale >= self::GAP_WIDTH) {
                $text = self::GAP;
                $this->gaps = true;
            } else {
                $text = '';
            }
        }
        // Every right-to-left letter starts with a byte from 0xD6 up in UTF-8, which rules out most text at a glance.
        if (!$this->rtl && $text >= "\xD6" && preg_match(Utf::RIGHT_TO_LEFT, $text)) {
            $this->rtl = true;
        }
        if ($this->codeTextBytes > self::CACHE_BYTES) {
            $this->codeText = [];
            $this->turned = null;
            $this->codeTextBytes = 0;
        }
        $this->codeTextBytes += strlen($text) + 64;
        return $this->codeText[$code] = $text;
    }
}
