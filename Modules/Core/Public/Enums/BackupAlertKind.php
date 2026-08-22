<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// The system_alerts.kind values the backup machinery emits: `backup_corrupt`
// from the db:backup and db:restore integrity failures, `backup_overdue` from
// the freshness probe. system_alerts.kind has no CHECK trigger and every
// module mints its own kinds, so a raiser's spelling is the whole contract
// between it and the surfaces that read the row back.
enum BackupAlertKind: string
{
    case Corrupt = 'backup_corrupt';

    case Overdue = 'backup_overdue';
}
