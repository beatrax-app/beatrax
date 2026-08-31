<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Enums;

// Which lifecycle slice of the inbox is on screen. The case value is a URL
// query parameter, so a bookmarked value outside this enum lands on the
// default rather than failing the request. Both sibling review screens name
// their tabs this way: DriftPageTab and ReviewTab.
enum NotificationTab: string
{
    case Unread = 'unread';

    case All = 'all';

    case Dismissed = 'dismissed';

    // The value the page reads as when the query string omits it or carries
    // something outside this enum, named so #[Url]'s `except:` and the
    // fallback cannot drift apart.
    public const string DEFAULT = 'unread';

    public function labelKey(): string
    {
        return 'notifications::inbox.tabs.'.$this->value;
    }

    public function emptyHeadingKey(): string
    {
        return 'notifications::inbox.empty.'.$this->value.'.heading';
    }

    public function emptyBodyKey(): string
    {
        return 'notifications::inbox.empty.'.$this->value.'.body';
    }
}
