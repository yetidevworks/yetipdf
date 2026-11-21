<?php

declare(strict_types=1);

namespace YetiPdf\Read\Font;

/** Anything that can say which text a character code stands for. */
interface CodeMap
{
    /** The text for a code, or null when this map does not know it. */
    public function get(int $code): ?string;
}

// The name this interface had before 1.3 (yetidevworks/yetipdf), kept until 2.0: see legacy.php.
if (!\interface_exists('YetiPdf\\Font\\CodeMap', false)) {
    \class_alias(CodeMap::class, 'YetiPdf\\Font\\CodeMap', false);
}
