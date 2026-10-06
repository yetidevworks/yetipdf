<?php

declare(strict_types=1);

namespace YetiPdf;

use YetiPdf\Core\File;
use YetiPdf\Exception\InvalidPdfException;

/**
 * Entry point.
 *
 *     foreach (YetiPdf::open('manual.pdf')->pages() as $number => $text) { ... }
 */
final class YetiPdf
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
