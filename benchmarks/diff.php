<?php
// usage: diff.php <pdf>  -- words pdftotext has that yetipdf lacks, and the reverse
require __DIR__ . '/tok.php';
require dirname(__DIR__) . '/vendor/autoload.php';
$pdf = $argv[1];
$r = tokens((string)shell_exec('pdftotext -enc UTF-8 ' . escapeshellarg($pdf) . ' - 2>/dev/null'));
$doc = YetiPdf\YetiPdf::open($pdf); $c = tokens($doc->text());
$miss = []; foreach ($r as $w => $n) { $d = $n - ($c[$w] ?? 0); if ($d > 0) $miss[$w] = $d; } arsort($miss);
$ex = []; foreach ($c as $w => $n) { $d = $n - ($r[$w] ?? 0); if ($d > 0) $ex[$w] = $d; } arsort($ex);
$fmt = fn($a) => implode(' ', array_map(fn($w, $n) => "$w($n)", array_keys(array_slice($a, 0, 45, true)), array_slice($a, 0, 45)));
printf("%s: ref %d words, missing %d, extra %d\n missing: %s\n extra: %s\n warnings: %s\n", basename($pdf), array_sum($r), array_sum($miss), array_sum($ex), $fmt($miss), $fmt($ex), implode(' | ', array_slice($doc->warnings(), 0, 4)));
