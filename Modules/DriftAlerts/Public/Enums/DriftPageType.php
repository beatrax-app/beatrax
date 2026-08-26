<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Enums;

// Which of the two alert kinds the page is showing. The case value is a URL
// query parameter, and the choice selects which query the render reads from,
// so a mistyped literal would silently show the other kind's empty state.
enum DriftPageType: string
{
    case Drift = 'drift';

    case Anomaly = 'anomaly';

    // The value the page reads as when the query string omits it or carries
    // something outside this enum, named so #[Url]'s `except:` and the
    // fallback cannot drift apart.
    public const string DEFAULT = 'drift';

    public function labelKey(): string
    {
        return 'drift-alerts::alerts.type.'.$this->value;
    }

    // Two nav items land here — "Drift Alerts" and "Unusual charges" — and the
    // page carried one name for both, so tapping the second arrived at a screen
    // headed with the first one's name. The toggle already labels them apart in
    // every locale, so each type names its own screen.
    public function headingKey(): string
    {
        return $this === self::Anomaly ? $this->labelKey() : 'drift-alerts::alerts.heading';
    }

    public function pageTitleKey(): string
    {
        return $this === self::Anomaly ? $this->labelKey() : 'drift-alerts::alerts.page_title';
    }
}
