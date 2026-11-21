<?php

declare(strict_types=1);

namespace YetiPdf\Read\Exception;

/** The PDF is encrypted and could not be opened with the password given (or with no password). */
class EncryptedException extends PdfException
{
}

// The name this class had before 1.3 (yetidevworks/yetipdf), kept until 2.0: see legacy.php.
if (!\class_exists('YetiPdf\\Exception\\EncryptedException', false)) {
    \class_alias(EncryptedException::class, 'YetiPdf\\Exception\\EncryptedException', false);
}
