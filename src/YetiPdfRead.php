<?php

declare(strict_types=1);

namespace YetiPdf\Read;

use YetiPdf\Read\Core\File;
use YetiPdf\Read\Exception\InvalidPdfException;

/**
 * Entry point.
 *
 *     foreach (YetiPdfRead::open('manual.pdf')->pages() as $number => $text) { ... }
 *
 * Named YetiPdfRead since 1.3.1, so it can be imported next to YetiPDF Write's YetiPdfWrite
 * without renaming either one.
 */
final class YetiPdfRead
{
    /**
     * @throws Exception\InvalidPdfException when the file is missing or is not a PDF
     * @throws Exception\EncryptedException when the PDF needs a password that was not given
     */
    public static function open(string $path, ?Options $options = null): Document
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new InvalidPdfException("Cannot read $path");
        }
        return self::parse($data, $options);
    }

    /**
     * @throws Exception\InvalidPdfException
     * @throws Exception\EncryptedException
     */
    public static function parse(string $bytes, ?Options $options = null): Document
    {
        $options ??= new Options();
        return new Document(new File($bytes, $options->password), $options);
    }
}

// The names this class had before 1.3.1, kept until 2.0 (deprecated): YetiPdf\YetiPdf up to 1.2
// (yetidevworks/yetipdf) and YetiPdf\Read\YetiPdf in 1.3.0. See legacy.php.
if (!\class_exists('YetiPdf\\YetiPdf', false)) {
    \class_alias(YetiPdfRead::class, 'YetiPdf\\YetiPdf', false);
}
if (!\class_exists('YetiPdf\\Read\\YetiPdf', false)) {
    \class_alias(YetiPdfRead::class, 'YetiPdf\\Read\\YetiPdf', false);
}
