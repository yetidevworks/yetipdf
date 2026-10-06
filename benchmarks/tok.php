<?php
function tokens(string $t): array {
    if (!mb_check_encoding($t, 'UTF-8')) { $t = mb_convert_encoding($t, 'UTF-8', 'UTF-8'); }
    $t = Normalizer::normalize($t, Normalizer::FORM_KC) ?: $t;
    $t = mb_strtolower($t);
    preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}]|[\p{L}\p{N}]+/u', $t, $m);
    $out = [];
    foreach ($m[0] as $w) {
        // single non-CJK characters are noise for search
        if (mb_strlen($w) < 2 && !preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $w)) continue;
        $out[$w] = ($out[$w] ?? 0) + 1;
    }
    return $out;
}
