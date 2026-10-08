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

    /** Single-byte codes (Type1, TrueType, Type3) or multi-byte (Type0). */
    public bool $simple = true;
    /** Simple fonts: byte => replacement text for strtr(); omitted bytes are unchanged. */
    public array $map = [];
    /** Code (simple) or CID (composite) => glyph width in glyph units. */
    public array $widths = [];
    public float $defaultWidth = 500.0;
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
        } elseif ($this->charset !== null) {
            $text = (string)@mb_convert_encoding($s, 'UTF-8', $this->charset);
            $this->n = mb_strlen($text, 'UTF-8');
            $this->w = $this->n * $this->defaultWidth * $this->scale;
            $this->sp = 0;
        } else {
            $text = $this->decodeComposite($s);
        }

        if (strlen($s) <= 48) {
            if (count($this->memo) >= self::MEMO_LIMIT) {
                $this->memo = [];
            }
            $this->memo[$s] = [$text, $this->w, $this->n, $this->sp];
        }
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

        if ($this->codespaces === []) {
            $codes = $this->codeBytes === 1 ? unpack('C*', $s) : unpack('n*', strlen($s) & 1 ? $s . "\0" : $s);
            foreach ($codes ?: [] as $code) {
                $text .= $this->codeText[$code] ?? $this->lookup($code);
                $w += $widths[$code] ?? $dw;
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
                $w += $widths[$code] ?? $dw;
                $n++;
                $i += $matched;
            }
        }

        $this->w = $w * $this->scale;
        $this->n = $n;
        $this->sp = $sp;
        return $text;
    }

    private function lookup(int $code): string
    {
        $text = $this->toUnicode?->get($code);
        if ($text === null) {
            if ($this->codesAreUnicode) {
                $text = ($code >= 0xD800 && $code <= 0xDFFF) ? '' : Utf::chr($code);
            } else {
                $text = '';
            }
        }
        return $this->codeText[$code] = $text;
    }
}
