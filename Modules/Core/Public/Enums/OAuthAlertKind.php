<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// The system_alerts.kind values raised about an OAuth credential the app can
// no longer use. The separators disagree because rows already on disk and the
// dedup queries that read them back are pinned to these exact spellings, so
// normalising them is a migration rather than an edit here.
enum OAuthAlertKind: string
{
    case ReconsentRequired = 'oauth_reconsent_required';

    case ReauthRequired = 'oauth.reauth_required';

    case ScrubSetFailed = 'oauth_scrub_set_failed';

    // Which kinds a reader clears by re-authorising a mailbox. The scrub-set
    // failure is this machine's log redaction going offline, which no amount
    // of re-consenting touches, so it gets no link.
    public static function promptsReauthorisation(string $kind): bool
    {
        return match (self::tryFrom($kind)) {
            self::ReconsentRequired, self::ReauthRequired => true,
            default => false,
        };
    }
}
