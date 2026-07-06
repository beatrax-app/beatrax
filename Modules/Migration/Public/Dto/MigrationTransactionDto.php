<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * One parsed source transaction, already reduced to the shape
 * `Modules\Migration`'s promote step maps onto `CanonicalTransaction` +
 * (when split) a `SaveTransactionSplit::save()` legs array.
 *
 * `amount` is the WHOLE-transaction settled amount — for a split parent this
 * is the sum of every `splits` leg (mirrors Actual's own `is_parent` row,
 * whose `amount` already carries the parent total; YNAB4/nYNAB have no
 * explicit parent row, so the parser synthesizes one by summing the grouped
 * split legs it reconstructs from the `"Split (n/m)"` memo convention).
 * `categorySourceExternalId` is null exactly when `splits` is non-empty
 * (category lives on each leg, never on the split parent — matches
 * `transactions.category IS NULL` for Actual's own `is_parent` rows).
 *
 * `splits` is empty for a non-split row. Each entry is a plain array shape
 * (not a nested Data object, to keep this phase's DTO surface at exactly the
 * ten IR types PATTERNS.md scopes) carrying:
 *   `category_source_external_id` (?string), `amount` (Money), `note` (?string).
 * The promote step (Plan 06) validates `array_sum(leg minors) === amount`
 * before calling `SaveTransactionSplit::save()` — the same sum-gate
 * `SaveTransactionSplit` itself enforces (`SplitSumMismatchException`).
 *
 * `transferCounterpartSourceExternalId` is set on BOTH legs of a detected
 * transfer pair (YNAB4/nYNAB's `"Transfer : <Account>"` payee convention;
 * Actual's mutual `transfer_id` / `payee.transfer_acct`) so the promote step
 * can link them without re-deriving the pairing logic — `Transfers\
 * PairsTransferLegs::pairOrphansForUser()` still runs once after promotion as
 * the authoritative sweep (Pitfall — CSV textual pairing is advisory, the
 * real domain pairing pass is canonical).
 *
 * `clearedStatus` is `'uncleared'|'cleared'|'reconciled'` — YNAB4/nYNAB's
 * `Cleared` column only ever yields the first two (RESEARCH.md: "reconciled
 * status is not exportable" from that source); Actual's `reconciled` boolean
 * makes the third value reachable.
 */
final class MigrationTransactionDto extends Data
{
    /**
     * @param  array<int, array{category_source_external_id: ?string, amount: Money, note: ?string}>  $splits
     * @param  array<int|string, mixed>  $rawPayload
     */
    public function __construct(
        public readonly ?string $sourceExternalId,
        public readonly string $accountSourceExternalId,
        public readonly CarbonImmutable $postedAt,
        public readonly Money $amount,
        public readonly ?string $payeeSourceExternalId,
        public readonly ?string $categorySourceExternalId,
        public readonly ?string $description,
        /** 'uncleared' | 'cleared' | 'reconciled' */
        public readonly string $clearedStatus,
        public readonly int $sourceRowIndex,
        public readonly array $rawPayload,
        public readonly ?string $transferCounterpartSourceExternalId = null,
        public readonly array $splits = [],
    ) {}
}
