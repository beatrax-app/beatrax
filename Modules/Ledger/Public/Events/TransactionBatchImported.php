<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Events;

/**
 * Dispatched ONCE per `RecordTransactions::__invoke()` call, AFTER every
 * chunk has committed — the opposite altitude to
 * `Modules\Import\Public\Events\TransactionImported`, which fires per
 * inserted row INSIDE a chunk's own transaction.
 *
 * NOT dispatched at all when `$insertedCount === 0` — a batch that inserted
 * nothing (e.g. a full-duplicate re-import) has nothing to announce.
 *
 * Unlike `TransactionImported`, this event is dispatched OUTSIDE any open
 * DB transaction, satisfying D-28 / WR-06's emit-after-commit contract for
 * free: each `persistChunk()` call has already committed independently by
 * the time this is dispatched, so there is no outer transaction to close
 * first.
 *
 * `$sourceFormats` is the DISTINCT, sorted set of
 * `Modules\Ledger\Public\Dto\CanonicalTransaction::$sourceFormat` values
 * across the rows that actually inserted in this batch (duplicates
 * excluded) — a `list<string>`, never a single scalar, because a batch can
 * legitimately mix formats (e.g. a manual reconciliation batch that lands
 * alongside receipt-derived rows). Listeners route on whitelist membership
 * against this list rather than a single value — see
 * `Modules\Notifications\Internal\Listeners\PersistCoalescedImport`, which
 * uses it to make the receipts-vs-import split total instead of a coin flip.
 */
final readonly class TransactionBatchImported
{
    /**
     * @param  list<string>  $sourceFormats
     */
    public function __construct(
        public int $userId,
        public int $insertedCount,
        public array $sourceFormats,
    ) {}
}
