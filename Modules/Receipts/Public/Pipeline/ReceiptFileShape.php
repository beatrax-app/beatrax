<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Pipeline;

use Modules\Core\Public\Support\FileHead;
use Modules\Ingestion\Public\Enums\SourceFormat;

// Which receipt transport a file actually is, read off its own bytes rather
// than off the leaf a reader picked from a list. The two are separable with
// certainty, so nothing here guesses: the screen that offers the choice and
// the stage that reads the file both ask this instead of trusting the pick.
final class ReceiptFileShape
{
    private const string UTF8_BOM = "\xEF\xBB\xBF";

    // Archive before message: every message inside an archive carries the same
    // RFC 822 headers a lone message does, so the .eml signature matches an
    // archive too, and only the mboxrd "From " line at the very start of the
    // file separates the two.
    public static function of(string $localPath): ?SourceFormat
    {
        $head = self::head($localPath);

        if ($head === null) {
            return null;
        }

        if (str_starts_with($head, MboxHeaderProfile::MBOX_PREFIX)) {
            return SourceFormat::Mbox;
        }

        return preg_match(EmlHeaderProfile::SIGNATURE_REGEX, $head) === 1
            ? SourceFormat::Eml
            : null;
    }

    private static function head(string $localPath): ?string
    {
        if (! is_file($localPath) || ! is_readable($localPath)) {
            return null;
        }

        $handle = @fopen($localPath, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $head = (string) fread($handle, FileHead::BYTES);
        } finally {
            fclose($handle);
        }

        return str_starts_with($head, self::UTF8_BOM)
            ? substr($head, strlen(self::UTF8_BOM))
            : $head;
    }
}
