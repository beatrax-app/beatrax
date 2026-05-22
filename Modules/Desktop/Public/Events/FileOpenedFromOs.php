<?php

declare(strict_types=1);

namespace Modules\Desktop\Public\Events;

/**
 * Emitted when the operating system hands the desktop shell a file to
 * open — a `.csv` bank/PayPal export or a `.eml` email receipt the user
 * double-clicked or dropped onto the app icon.
 *
 * A dumb DTO carrying the file-open intent. The path is validated (file
 * exists, extension allow-listed) at the emission boundary; this event
 * does not re-check it. `extension` is the lower-cased extension without
 * the leading dot — currently `'eml'` or `'csv'`.
 */
final readonly class FileOpenedFromOs
{
    public function __construct(
        public string $path,
        public string $extension,
    ) {}
}
