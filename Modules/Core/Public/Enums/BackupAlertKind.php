<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// The system_alerts.kind values the backup machinery emits: db:backup and
// db:restore raise Corrupt, the freshness probe raises Overdue. The column
// has no CHECK trigger and every module mints its own kinds, so a raiser's
// spelling is the whole contract with the surfaces that read the row back.
enum BackupAlertKind: string
{
    case Corrupt = 'backup_corrupt';

    case Overdue = 'backup_overdue';
}
