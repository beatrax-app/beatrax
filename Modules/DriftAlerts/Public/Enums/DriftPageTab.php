<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Enums;

// Which lifecycle slice of the alert list is on screen. The case value is a
// URL query parameter and is shared with the Anomaly partials the page mounts,
// so it is named here rather than spelled at each comparison.
enum DriftPageTab: string
{
    case Open = 'open';

    case History = 'history';

    case Dismissed = 'dismissed';

    // The value the page reads as when the query string omits it or carries
    // something outside this enum, named so #[Url]'s `except:` and the
    // fallback cannot drift apart.
    public const string DEFAULT = 'open';

    public function labelKey(): string
    {
        return 'drift-alerts::alerts.tabs.'.$this->value;
    }
}
