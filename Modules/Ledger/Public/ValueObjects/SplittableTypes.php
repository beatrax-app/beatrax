<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

/**
 * Single source of truth for the transaction `type` values that a split may
 * be created for (Phase 13.1, Req 6 / D-07/D-08).
 *
 * `transfer_in`/`transfer_out` are excluded — they own the paired
 * equal-and-opposite invariant via `pair_transaction_id` and can never carry
 * category legs.
 *
 * Extracted (IN-04) so the write-path gate (`SaveTransactionSplit`), the UI
 * gate (`TransactionDetail`), and the reclassify auto-unsplit coordination
 * (CR-01) all consume ONE list. Previously this constant was duplicated in
 * three places; that drift is exactly what let CR-01 slip through — the UI
 * gate and the persisted-leg lifecycle could disagree.
 */
final class SplittableTypes
{
    /** @var list<string> */
    public const TYPES = ['expense', 'income', 'fee', 'refund', 'adjustment'];

    private function __construct() {}

    /** Whether a transaction of `$type` may carry split legs. */
    public static function contains(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }
}
