<?php

declare(strict_types=1);

namespace YetiPdf\Read\Exception;

/** The PDF is encrypted and could not be opened with the password given (or with no password). */
class EncryptedException extends PdfException
{
}
