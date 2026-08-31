<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Enums;

use Modules\Core\Public\Enums\InboxMessageStatus;

// The three answers a SenderMatcher can give about one message. The values are
// the InboxMessageStatus each answer is stored as, minus Fetched, which is the
// state a message is in BEFORE matching and so is never an outcome.
enum MatchOutcomeKind: string
{
    case Parsed = 'parsed';

    case Skipped = 'skipped';

    case Unmatched = 'unmatched';

    public function toInboxStatus(): InboxMessageStatus
    {
        return match ($this) {
            self::Parsed => InboxMessageStatus::Parsed,
            self::Skipped => InboxMessageStatus::Skipped,
            self::Unmatched => InboxMessageStatus::Unmatched,
        };
    }
}
