<?php
require __DIR__ . '/tok.php';
[$_, $list, $outDir] = $argv;
foreach (file($list, FILE_IGNORE_NEW_LINES) as $pdf) {
    $f = "$outDir/ref." . md5($pdf) . '.txt';
    if (!is_file($f)) continue;
    if (array_sum(tokens(file_get_contents($f))) >= 10) echo $pdf, "\n";
}
