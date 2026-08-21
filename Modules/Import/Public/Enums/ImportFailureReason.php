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

    public function label(): string
    {
        return Lang::get('import::preview.errors.'.$this->value);
    }
}
