<?php
// usage: runner.php <lib> <pdf> <outdir>
// Extracts one PDF with one library in its own process, so a crash, hang or memory blowup is recorded and not fatal to the run.
[$_, $lib, $pdf, $outDir] = $argv;
ini_set('memory_limit', '2048M');
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE);
set_time_limit(90);
require dirname(__DIR__) . '/vendor/autoload.php';
// The libraries to compare against are optional: composer install -d benchmarks
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}
$key = md5($pdf);
$res = ['lib' => $lib, 'pdf' => $pdf, 'ok' => false, 'error' => null, 'time' => null, 'mem' => null];
$start = hrtime(true);
register_shutdown_function(function () use (&$res, $outDir, $lib, $key, $start) {
    if (!$res['ok'] && $res['error'] === null) {
        $e = error_get_last();
        $res['error'] = 'FATAL: ' . substr($e['message'] ?? 'unknown', 0, 200);
    }
    $res['time'] ??= (hrtime(true) - $start) / 1e9;
    $res['mem'] = memory_get_peak_usage(true);
    file_put_contents("$outDir/$lib.$key.json", json_encode($res));
});
try {
    switch ($lib) {
        case 'prinsfrank':
            $text = (new PrinsFrank\PdfParser\PdfParser())->parseFile($pdf)->getText();
            break;
        case 'prinsfrank-file':
            $text = (new PrinsFrank\PdfParser\PdfParser())->parseFile($pdf, false)->getText();
            break;
        case 'yetipdf':
            $text = YetiPdf\YetiPdf::open($pdf)->text();
            break;
        case 'smalot':
            $text = (new Smalot\PdfParser\Parser())->parseFile($pdf)->getText();
            break;
        default:
            throw new RuntimeException('unknown lib');
    }
    $res['time'] = (hrtime(true) - $start) / 1e9;
    file_put_contents("$outDir/$lib.$key.txt", $text);
    $res['ok'] = true;
} catch (Throwable $e) {
    $res['error'] = get_class($e) . ': ' . substr($e->getMessage(), 0, 200);
}
