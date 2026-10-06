<?php

declare(strict_types=1);

namespace YetiPdf\Font;

/** Turns glyph names ("Aacute", "f_i", "uni20AC") into text. */
final class GlyphList
{
    /** @var array<string, string>|null */
    private static ?array $list = null;

    /** Names TeX fonts use that the Adobe list does not have. */
    private const EXTRA = [
        'visiblespace' => ' ', 'nbspace' => ' ', 'dotlessj' => "\u{0237}", 'ff' => 'ff', 'fi' => 'fi', 'fl' => 'fl',
        'ffi' => 'ffi', 'ffl' => 'ffl', 'IJ' => 'IJ', 'ij' => 'ij', 'sfthyphen' => '-', 'hyphenchar' => '-',
        'compwordmark' => '', 'cwm' => '', 'perthousandzero' => '', 'ng' => "\u{014B}", 'Ng' => "\u{014A}",
        'angbracketleft' => "\u{27E8}", 'angbracketright' => "\u{27E9}", 'circlecopyrt' => "\u{00A9}",
        'bulletmath' => "\u{2219}", 'minusplus' => "\u{2213}", 'negationslash' => '/', 'mapsto' => "\u{21A6}",
        'owner' => "\u{220B}", 'triangle' => "\u{25B3}", 'ceilingleft' => "\u{2308}", 'ceilingright' => "\u{2309}",
        'floorleft' => "\u{230A}", 'floorright' => "\u{230B}", 'bardbl' => "\u{2016}", 'latticetop' => "\u{22A4}",
        'turnstileleft' => "\u{22A2}", 'turnstileright' => "\u{22A3}", 'similarequal' => "\u{2243}",
        'lessmuch' => "\u{226A}", 'greatermuch' => "\u{226B}", 'precedes' => "\u{227A}", 'follows' => "\u{227B}",
        'arrowdblboth' => "\u{21D4}", 'epsilon1' => "\u{03F5}", 'theta1' => "\u{03D1}", 'pi1' => "\u{03D6}",
        'rho1' => "\u{03F1}", 'sigma1' => "\u{03C2}", 'phi1' => "\u{03D5}", 'vector' => "\u{20D7}",
        'tie' => "\u{2040}", 'star' => "\u{22C6}", 'diamondmath' => "\u{22C4}", 'openbullet' => "\u{25E6}",
        'equivasymptotic' => "\u{224D}", 'unionmulti' => "\u{228E}", 'unionsq' => "\u{2294}",
        'intersectionsq' => "\u{2293}", 'wreathproduct' => "\u{2240}", 'nabla' => "\u{2207}",
        'natural' => "\u{266E}", 'sharp' => "\u{266F}", 'flat' => "\u{266D}", 'coproduct' => "\u{2210}",
    ];

    public static function toUnicode(string $name): string
    {
        self::$list ??= require dirname(__DIR__, 2) . '/data/glyphlist.php';
        $list = self::$list;

        if (isset($list[$name])) {
            return $list[$name];
        }
        if (isset(self::EXTRA[$name])) {
            return self::EXTRA[$name];
        }
        if ($name === '' || $name === '.notdef') {
            return '';
        }

        // "a.sc", "one.oldstyle": the part before the first dot names the character.
        $dot = strpos($name, '.');
        if ($dot !== false) {
            return $dot === 0 ? '' : self::toUnicode(substr($name, 0, $dot));
        }
        // "f_f_i": a ligature spelled out from its parts.
        if (str_contains($name, '_')) {
            $out = '';
            foreach (explode('_', $name) as $part) {
                $out .= self::toUnicode($part);
            }
            return $out;
        }
        if (preg_match('/^uni((?:[0-9A-F]{4})+)$/', $name, $m)) {
            $out = '';
            foreach (str_split($m[1], 4) as $hex) {
                $out .= Utf::chr((int)hexdec($hex));
            }
            return $out;
        }
        if (preg_match('/^u([0-9A-F]{4,6})$/', $name, $m)) {
            return Utf::chr((int)hexdec($m[1]));
        }
        return '';
    }
}
