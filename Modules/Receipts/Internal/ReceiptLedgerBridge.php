<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Categorization\Public\Contracts\AppliesAutoCategory;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\IdReadBack;
use Modules\Counterparties\Public\Pipeline\ResolvesCounterparties;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;
use Modules\Receipts\Public\Pipeline\ReceiptSourceAdapter;

// RecordReceipt persists the audit row and never writes to transactions, so
// every consumer of a parsed outcome owes the ledger this second half. The
// inbox job and the drop-folder scan both reach it here; the scan used to
// discard the outcome, which read as a successful import of nothing.
final readonly class ReceiptLedgerBridge
{
    // Not an ingestion format: no adapter parses it. It marks the ImportRun a
    // receipt bridged through when the reader never uploaded a file.
    private const string HANDOFF_FORMAT = 'inbox-handoff';

    public function __construct(
        private ReceiptSourceAdapter $receiptAdapter,
        private NormalizeStage $normalize,
        private AppliesAutoCategory $autoCategory,
        private ResolvesCounterparties $resolveCounterparty,
        private RecordsTransactions $recorder,
        private Clock $clock,
        private DatabaseManager $db,
    ) {}

    // The synthetic per-provider IBAN resolves to the user's matching Account,
    // absent which the write is skipped and the reader is asked to name it in
    // the preview wizard. Returns the ImportRun id in play, created lazily so
    // a walk that parses nothing never leaves an orphan run behind.
    public function bridge(ParsedReceiptDto $parsed, User $user, ?int $importRunId, SourceFormat $sourceFormat): ?int
    {
        $account = Account::query()
            ->where('user_id', $user->id)
            ->where('iban', $parsed->ownIban)
            ->first();
        if ($account === null) {
            return $importRunId;
        }

        $importRunId ??= $this->resolveHandoffRun($user);
        $source = $this->receiptAdapter->toSourceDto($parsed, sourceRowIndex: 0);
        $canonical = $this->normalize->run($source, $account->id, $user, importRunId: $importRunId, sourceFormat: $sourceFormat->value);

        // The stages a receipt uploaded through the wizard pays for. Reaching
        // the recorder straight off the normaliser gave the same message two
        // outcomes: the reader's rules and their merchant list saw a receipt
        // they had uploaded and never one their inbox had fetched.
        $canonical = $this->autoCategory->apply($canonical, $user)->canonical;
        $canonical = $this->resolveCounterparty->run($canonical, $user);

        $ordinal = $this->occurrenceOrdinalFor($canonical, $user);
        if ($ordinal === null) {
            return $importRunId;
        }

        ($this->recorder)([$canonical->withOccurrenceOrdinal($ordinal)], $user);

        return $importRunId;
    }

    // A receipt is its own document, so there is no file to count occurrences
    // within and the ledger is asked instead. Null means this very message is
    // already in the ledger; a message with no reference of its own cannot be
    // told apart from a second reading of itself and stays the sole occurrence.
    /**
     * @link ../../../.docs/architecture/ingestion-pipeline.md#the-occurrence-ordinal
     */
    private function occurrenceOrdinalFor(CanonicalTransaction $tx, User $user): ?int
    {
        if ($tx->sourceRef === null) {
            return 0;
        }

        if ($this->sameOccurrenceAs($tx, $user)->where('source_ref', $tx->sourceRef)->exists()) {
            return null;
        }

        // Counted over the receipts alone: a statement that already booked this
        // occurrence is the row this receipt belongs on, and stepping past it
        // would write the purchase a second time instead of deduping into it.
        $highest = $this->sameOccurrenceAs($tx, $user)
            ->whereIn('source_format', SourceFormat::receiptFormats())
            ->max('occurrence_ordinal');

        return is_numeric($highest) ? (int) $highest + 1 : 0;
    }

    // The seven columns the dedup tuple reads before the ordinal completes it,
    // which is the group an ordinal numbers a row within.
    private function sameOccurrenceAs(CanonicalTransaction $tx, User $user): Builder
    {
        return $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $tx->accountId)
            ->where('posted_at', $tx->postedAt->toDateString())
            ->where('booked_at', $tx->bookedAt->toDateTimeString())
            ->where('amount_minor', $tx->amountMinor)
            ->where('currency', $tx->currency)
            ->where('counterparty_normalized', $tx->counterpartyNormalized);
    }

    private function resolveHandoffRun(User $user): int
    {
        // Sentinel path for raw_file_path — a handoff write has no on-disk
        // source file. The sha256 anchor is stable per user+hour, and
        // import_runs is UNIQUE (user_id, sha256): the second run of the hour
        // must therefore adopt the first row, not insert beside it.
        $hourStamp = $this->clock->now()->format('Y-m-d-H');
        $rawPathSentinel = '__INBOX_HANDOFF__/user-'.$user->id.'/'.$hourStamp;
        $runAnchor = sprintf('%s:%d:%s', self::HANDOFF_FORMAT, $user->id, $hourStamp);
        $now = $this->clock->now()->toDateTimeString();

        $match = ['user_id' => $user->id, 'sha256' => hash('sha256', $runAnchor)];

        ImportRun::query()->firstOrCreate($match, [
            'source_format' => self::HANDOFF_FORMAT,
            'raw_file_path' => $rawPathSentinel,
            'uploaded_at' => $now,
            'status' => ImportRunStatus::Confirmed->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // The id is read back by the match, never carried out of firstOrCreate():
        // it ends in insertGetId(), lastInsertId() is per connection, and the
        // badge listener writes a `cache` row from inside this INSERT's own event.
        // Every receipt bridged here would name a run that does not exist.
        return IdReadBack::of($this->db->connection(), 'import_runs', $match);
    }
}
