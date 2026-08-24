<?php

declare(strict_types=1);

namespace Modules\Import\Public\Enums;

use Modules\Core\Public\Support\Lang;

// What the reader is told when a row or a file will not read. An exception
// message is machine text — it names internal classes and, for the app-lock
// case, the reader's own user id — so the screen gets one of these instead and
// the message goes to the log.
enum ImportFailureReason: string
{
    case UnknownAccount = 'unknown_account';

    case AppLocked = 'app_locked';

    case RowUnreadable = 'row_unreadable';

    case FileUnreadable = 'file_unreadable';

    case PdfReaderUnavailable = 'pdf_reader_unavailable';

    // The line under "This file could not be read". Only the default names the
    // header row, which is the wrong advice for a file that failed for a reason
    // already known -- a locked app, or a PDF reader this install does not have.
    public function fileCause(): string
    {
        return $this === self::FileUnreadable
            ? Lang::get('import::preview.failed.likely_cause')
            : $this->label();
    }

    public function label(): string
    {
        return Lang::get('import::preview.errors.'.$this->value);
    }

    // A row that failed without recording why IS a row that could not be read,
    // so the absent reason resolves here rather than at each screen. The two
    // that rendered it had drifted onto different sentences, one of which
    // described the record instead of the row.
    public static function labelFor(?self $reason): string
    {
        return ($reason ?? self::RowUnreadable)->label();
    }
}
