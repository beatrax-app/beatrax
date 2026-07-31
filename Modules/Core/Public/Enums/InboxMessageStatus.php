<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// The lifecycle status of an ingested inbox message / imported file: fetched
// then parsed, or skipped/unmatched. Shared by EmailScan (inbox_messages) and
// Receipts (file_imports), whose queries validate a caller-supplied status
// against it, so the vocabulary lives once in Core.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
enum InboxMessageStatus: string
{
    case Fetched = 'fetched';

    case Parsed = 'parsed';

    case Skipped = 'skipped';

    case Unmatched = 'unmatched';
}
