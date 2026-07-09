<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Receipts\Public\Dto\ChainHintPayload\FundedByCardPayload;
use Modules\Receipts\Public\Dto\ChainHintPayload\RefundOfPayload;
use Modules\Receipts\Public\Events\ChainHintDetected;
use Psr\Log\LoggerInterface;

/**
 * Bridges the canonical-write event (`TransactionImported`, dispatched
 * by `Modules\Ledger\Public\Actions\RecordTransactions` when each row
 * is INSERTed) into the structured cross-source clue event
 * (`ChainHintDetected`, consumed by the Chains module's
 * `CreateChainLinkFromHint` listener).
 *
 * Why this bridge exists rather than dispatching from
 * `RecordReceipt` directly: `RecordReceipt` does not write the
 * canonical `transactions` row — it owns the `file_imports` row
 * lifecycle and the matcher dispatch, then yields a
 * `MatchOutcomeDto` back to the caller (`ParseStage`). The canonical
 * row is persisted later by `RecordTransactions` after
 * `NormalizeStage` + `FingerprintStage` finalise the canonical
 * shape. Only at the moment `TransactionImported` fires is the
 * just-inserted `transactions.id` known — which is the value
 * `ChainHintDetected.sourceTransactionId` must carry so the Chains
 * module's INSERT into `chain_links` satisfies the FK constraint
 * on `from_transaction_id`.
 *
 * The chain hints themselves ride through the canonical pipeline on
 * `transactions.raw_payload['chain_hints']`: the receipt matcher
 * populates `ParsedReceiptDto::chainHints`, `ReceiptSourceAdapter`
 * serialises them into `SourceTransactionDto::rawPayload`,
 * `NormalizeStage` passes the payload through verbatim, and
 * `RecordTransactions` casts the array into the JSON column. This
 * listener re-hydrates each entry by its `hint_type` discriminator,
 * builds the typed sub-DTO, and re-emits `ChainHintDetected`.
 *
 * Idempotency: `TransactionImported` is dispatched once per INSERT
 * (never for duplicates that `insertOrIgnore` dropped), so this
 * listener fires at most once per receipt-derived transaction.
 * Re-importing the same receipt is a no-op all the way down.
 *
 * Scope: the listener short-circuits when `transaction.source_format`
 * is not in the set of receipt formats (eml/mbox). CSV / CAMT /
 * MT940 / PDF transactions never carry `raw_payload['chain_hints']`
 * by construction, but the early return keeps the cost at one
 * `in_array()` check per non-receipt import.
 *
 * Cross-user safety: the listener trusts the User on the event
 * payload as authoritative for the `userId` field of the downstream
 * `ChainHintDetected`. `RecordTransactions` populates that field
 * from the `User` argument it received, so any cross-user leak would
 * have to originate upstream of this listener.
 *
 * @internal Subscribed in `ReceiptsServiceProvider::boot()`. The
 *           listener does NOT live in `Receipts/Public/` because it
 *           has no caller outside this module; only the framework
 *           dispatches it.
 *
 * D-05/RESEARCH A3 trigger-context finding: `TransactionImported` is
 * dispatched synchronously from inside `RecordTransactions::persistChunk()`,
 * so this listener always runs in the EXACT SAME process/call-frame as the
 * write it reacts to — whatever KEK availability applied when
 * `raw_payload` was encrypted (or pass-through-plaintext) at write time is
 * identical at this read, so the `EncryptedJsonCast` decrypt below can
 * never mismatch its own write. `ProcessFetchedInboxMessagesJob` and
 * `ScanInboxDropFolderJob` (both `ShouldQueue`) DO drive this path from a
 * queue-worker daemon with no live app-lock session — a residual, separate
 * gap (daemon writes bypass mandatory encryption for receipt-sourced rows)
 * tracked outside this listener's scope. `is_array($rawPayload)` failing
 * despite a non-empty raw stored column is logged rather than silently
 * dropped.
 */
final class DispatchChainHintsFromReceipt
{
    /** Wire-format keys that may carry receipt chain hints. */
    private const RECEIPT_FORMATS = ['eml', 'mbox'];

    public function __construct(
        private readonly Dispatcher $events,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(TransactionImported $event): void
    {
        $transaction = $event->transaction;

        if (! in_array($transaction->source_format, self::RECEIPT_FORMATS, true)) {
            return;
        }

        $rawPayload = $transaction->raw_payload;
        if (! is_array($rawPayload)) {
            $rawStored = $transaction->getRawOriginal('raw_payload');
            if (is_string($rawStored) && $rawStored !== '') {
                $this->logger->warning(
                    'DispatchChainHintsFromReceipt: raw_payload present but failed to decrypt/decode — chain hints skipped.',
                    ['transaction_id' => $transaction->id],
                );
            }

            return;
        }

        $hints = $rawPayload['chain_hints'] ?? null;
        if (! is_array($hints) || $hints === []) {
            return;
        }

        $userId = $event->user->id;
        $sourceTransactionId = $transaction->id;

        foreach ($hints as $hint) {
            if (! is_array($hint)) {
                continue;
            }
            $rehydrated = $this->rehydrate($hint);
            if ($rehydrated === null) {
                continue;
            }
            [$hintType, $payload, $evidence] = $rehydrated;

            $this->events->dispatch(new ChainHintDetected(
                sourceTransactionId: $sourceTransactionId,
                hintType: $hintType,
                hintPayload: $payload,
                evidence: $evidence,
                userId: $userId,
            ));
        }
    }

    /**
     * Re-hydrate one serialised hint into a (hintType, payload,
     * evidence) tuple. Returns null when the entry is malformed so
     * the caller skips it without firing a half-formed event.
     *
     * @param  array<int|string, mixed>  $hint
     * @return array{string, object, string}|null
     */
    private function rehydrate(array $hint): ?array
    {
        $type = $hint['hint_type'] ?? null;
        if (! is_string($type)) {
            return null;
        }
        $rawEvidence = $hint['evidence'] ?? '';
        $evidence = is_string($rawEvidence) ? $rawEvidence : '';

        if ($type === 'funded_by_card') {
            $cardLast4 = $hint['card_last4'] ?? null;
            if (! is_string($cardLast4) || $cardLast4 === '') {
                return null;
            }

            return [$type, new FundedByCardPayload(cardLast4: $cardLast4), $evidence];
        }
        if ($type === 'refund_of') {
            $original = $hint['original_reference_id'] ?? null;
            if (! is_string($original) || $original === '') {
                return null;
            }

            return [$type, new RefundOfPayload(originalReferenceId: $original), $evidence];
        }

        return null;
    }
}
