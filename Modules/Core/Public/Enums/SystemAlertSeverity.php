<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// The three severities a system_alerts row can carry. The column stays
// string (enforced by a trigger); this enum is the one canonical spelling
// callers map through.
enum SystemAlertSeverity: string
{
    case Info = 'info';

    case Warning = 'warning';

    case Critical = 'critical';
}
