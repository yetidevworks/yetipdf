<?php

declare(strict_types=1);

namespace YetiPdf\Font;

/** Small UTF helpers, so the library does not need mbstring or iconv. */
final class Utf
{
    public static function chr(int $cp): string
    {
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                return '';
            }
            return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp > 0x10FFFF) {
            return '';
        }
        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }

    /**
     * UTF-16BE bytes to a list of code points.
     *
     * @return list<int>
     */
    public static function utf16Codepoints(string $bytes): array
    {
        if (strlen($bytes) === 1) {
            return [ord($bytes)];
        }
        $units = array_values(unpack('n*', $bytes) ?: []);
        $out = [];
        for ($i = 0, $n = count($units); $i < $n; $i++) {
            $u = $units[$i];
            if ($u >= 0xD800 && $u <= 0xDBFF && isset($units[$i + 1]) && $units[$i + 1] >= 0xDC00 && $units[$i + 1] <= 0xDFFF) {
                $out[] = 0x10000 + (($u - 0xD800) << 10) + ($units[++$i] - 0xDC00);
            } else {
                $out[] = $u;
            }
        }
        return $out;
    }

    public static function utf16(string $bytes): string
    {
        $out = '';
        foreach (self::utf16Codepoints($bytes) as $cp) {
            $out .= self::chr($cp);
        }
        return $out;
    }
}
