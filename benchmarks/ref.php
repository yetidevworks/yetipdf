<?php
// usage: ref.php <pdf> <outdir>   -- pdftotext reference
[$_, $pdf, $outDir] = $argv;
$key = md5($pdf);
$start = hrtime(true);
$cmd = 'pdftotext -enc UTF-8 ' . escapeshellarg($pdf) . ' ' . escapeshellarg("$outDir/ref.$key.txt") . ' 2>/dev/null';
exec($cmd, $o, $rc);
file_put_contents("$outDir/ref.$key.json", json_encode(['lib' => 'ref', 'pdf' => $pdf, 'ok' => $rc === 0, 'time' => (hrtime(true) - $start) / 1e9, 'size' => filesize($pdf)]));
