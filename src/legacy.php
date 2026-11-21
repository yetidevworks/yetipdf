<?php

declare(strict_types=1);

/*
 * The names this library had as yetidevworks/yetipdf (YetiPdf\YetiPdf, YetiPdf\Options and so on)
 * keep working: the first time code asks for one, every public class is aliased under its old
 * name, so old type hints and catch blocks match too. Deprecated; removed in 2.0.
 */
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'YetiPdf\\') || str_starts_with($class, 'YetiPdf\\Read\\') || str_starts_with($class, 'YetiPdf\\Write\\')) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    foreach (['YetiPdf', 'Document', 'Options', 'Exception\\PdfException', 'Exception\\InvalidPdfException', 'Exception\\EncryptedException'] as $name) {
        if (!class_exists('YetiPdf\\' . $name, false)) {
            class_alias('YetiPdf\\Read\\' . $name, 'YetiPdf\\' . $name);
        }
    }
});
