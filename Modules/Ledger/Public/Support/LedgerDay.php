<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Support;

use Carbon\CarbonInterface;
use Modules\Core\Public\Support\Fmt;

// The one day a row is allowed to show a reader. `transactions.posted_at` is
// what the ledger stores and what TransactionCursor sorts and pages on;
// `booked_at` is the issuer's own booking stamp, a different day on every row
// of a card statement, and a row naming it dates itself a day nothing writes.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-list-sorted-by-a-column-it-does-not-show
 */
final class LedgerDay
{
    // Formatted here rather than in each Blade, so a row DTO carries a string
    // the view prints unchanged and the six row types that print a day cannot
    // spell one differently. The rule above is the reason this exists at all:
    // it is one call to reach, where it used to be one comment to remember.
    public static function shown(CarbonInterface|string $postedAt): string
    {
        return Fmt::shortDate($postedAt);
    }
}
