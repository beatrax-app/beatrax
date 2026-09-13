<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Support;

use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Support\NewestTransactionFirst;

// Two detectors ask which of a merchant's charges came first, and a charge
// booked the same day as the anchor cannot be ordered by date. Ordering it by
// `id` made "first" mean "first written HERE": two devices called different
// charges the duplicate, and different charges the first-time.
/**
 * @link ../../../../.docs/architecture/an-ordering-that-picks.md#a-comparison-over-the-same-key
 */
final readonly class BackwardOnly
{
    use CoercesScalars;

    // Six columns bound from the row under test; the seventh is read through
    // its account_id, which NAMES the account rather than being compared —
    // what enters the comparison is the IBAN, and both devices hold that.
    public const string COMPARISON = NewestTransactionFirst::KEY_ACROSS_ACCOUNTS
        .' < (?, ?, ?, ?, ?, ?, (select iban from accounts where id = ?))';

    /**
     * @param  array<string, mixed>  $txn  the raw transactions row under test
     * @return list<int|string>
     */
    public static function anchorKey(array $txn): array
    {
        return [
            self::toString($txn['posted_at'] ?? null),
            self::toString($txn['booked_at'] ?? null),
            self::toInt($txn['amount_minor'] ?? null),
            self::toString($txn['currency'] ?? null),
            self::toString($txn['counterparty_normalized'] ?? null),
            self::toInt($txn['occurrence_ordinal'] ?? null),
            self::toInt($txn['account_id'] ?? null),
        ];
    }
}
