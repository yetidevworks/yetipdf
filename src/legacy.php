<?php

declare(strict_types=1);

/*
 * Until 1.3 this library was yetidevworks/yetipdf, with its classes directly under YetiPdf\
 * (YetiPdf\YetiPdf, YetiPdf\Options, YetiPdf\Core\File and so on). Every class file ends by
 * aliasing its old name, so the old names match as soon as a class is loaded under either name:
 * in type hints, catch blocks and instanceof too. This loader covers code that asks for a class
 * by its old name first, by loading the class under its new one. Deprecated; removed in 2.0.
 *
 * (A copy of 1.2 bundled separately, in another package's own vendor folder, defines its own
 * classes under the old names; those stay separate from these.)
 */
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'YetiPdf\\') && !str_starts_with($class, 'YetiPdf\\Read\\') && !str_starts_with($class, 'YetiPdf\\Write\\')) {
        $new = 'YetiPdf\\Read\\' . substr($class, 8);
        class_exists($new) || interface_exists($new);
    }
});
