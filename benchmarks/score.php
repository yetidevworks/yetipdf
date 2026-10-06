<?php
require __DIR__ . '/tok.php';
[$_, $list, $outDir] = $argv;
$libs = array_slice($argv, 3);
error_reporting(E_ALL & ~E_WARNING); $rows = [];
foreach (file($list, FILE_IGNORE_NEW_LINES) as $pdf) {
    $k = md5($pdf);
    $refJ = json_decode(file_get_contents("$outDir/ref.$k.json"), true);
    $ref = tokens(file_get_contents("$outDir/ref.$k.txt"));
    $refN = array_sum($ref);
    $row = ['pdf' => $pdf, 'real' => str_contains($pdf, '/real/'), 'size' => $refJ['size'], 'refN' => $refN, 'refTime' => $refJ['time']];
    foreach ($libs as $lib) {
        $jf = "$outDir/$lib.$k.json";
        if (!is_file($jf)) { $row[$lib] = ['status' => 'missing']; continue; }
        $j = json_decode(file_get_contents($jf), true);
        $r = ['status' => $j['ok'] ? 'ok' : 'fail', 'error' => $j['error'], 'time' => $j['time'], 'mem' => $j['mem'], 'recall' => 0.0, 'precision' => 0.0];
        if ($j['ok']) {
            $c = tokens(file_get_contents("$outDir/$lib.$k.txt"));
            $inter = 0;
            foreach ($ref as $w => $n) { $inter += min($n, $c[$w] ?? 0); }
            $cN = array_sum($c);
            $r['recall'] = $refN ? $inter / $refN : 0;
            $r['precision'] = $cN ? $inter / $cN : 0;
            $r['candN'] = $cN;
        }
        $row[$lib] = $r;
    }
    $rows[] = $row;
}
file_put_contents("$outDir/scores.json", json_encode($rows));
function pct(array $a, float $p) { sort($a); return $a ? $a[(int)floor((count($a) - 1) * $p)] : 0; }
foreach (['all' => fn($r) => true, 'ordinary docs' => fn($r) => $r['real'], 'pdf.js >=50 words' => fn($r) => !$r['real'] && $r['refN'] >= 50, 'pdf.js 10-49 words' => fn($r) => !$r['real'] && $r['refN'] < 50] as $name => $filter) {
    $set = array_values(array_filter($rows, $filter));
    printf("\n== %s (%d files)\n", $name, count($set));
    foreach ($libs as $lib) {
        $ok = array_filter($set, fn($r) => $r[$lib]['status'] === 'ok');
        $rec = array_map(fn($r) => $r[$lib]['recall'], $set); // failures count as 0
        $recOk = array_map(fn($r) => $r[$lib]['recall'], $ok);
        $prec = array_map(fn($r) => $r[$lib]['precision'], $ok);
        $mem = array_map(fn($r) => $r[$lib]['mem'] / 1048576, $set);
        $time = array_map(fn($r) => $r[$lib]['time'], $set);
        $tot = array_sum(array_column($set, 'refN')); $got = 0;
        foreach ($set as $r) { $got += $r[$lib]['recall'] * $r['refN']; }
        printf("%-16s ok %3d/%3d | recall mean %.1f%% median %.1f%% word-weighted %.1f%% | >=95%%: %d  >=80%%: %d  <50%%: %d | precision mean %.1f%% | mem median %.0fMB p90 %.0fMB max %.0fMB | time total %.1fs median %.2fs max %.1fs\n",
            $lib, count($ok), count($set), 100 * array_sum($rec) / max(1, count($rec)), 100 * pct($rec, .5), 100 * $got / max(1, $tot),
            count(array_filter($rec, fn($x) => $x >= .95)), count(array_filter($rec, fn($x) => $x >= .80)), count(array_filter($rec, fn($x) => $x < .50)),
            100 * array_sum($prec) / max(1, count($prec)), pct($mem, .5), pct($mem, .9), max($mem ?: [0]), array_sum($time), pct($time, .5), max($time ?: [0]));
    }
    printf("%-16s time total %.1fs\n", 'pdftotext', array_sum(array_column($set, 'refTime')));
}
