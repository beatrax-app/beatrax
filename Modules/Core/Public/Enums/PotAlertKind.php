<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// The `system_alerts.kind` the envelope cutover raises, spelled here beside
// the other kinds because the value is what the banner's two blades switch on
// and a kind spelled at the write site and again in a view drifts apart.
enum PotAlertKind: string
{
    case CategoryLinkRetired = 'pots.category_link_retired';

    // Warning, not info: money the reader had set aside is loose in an account
    // until they act, and the amber row is the one the banner draws a call to
    // action on.
    public function severity(): SystemAlertSeverity
    {
        return SystemAlertSeverity::Warning;
    }
}
