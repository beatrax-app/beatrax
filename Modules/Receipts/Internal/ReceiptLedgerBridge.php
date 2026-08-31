<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\IdReadBack;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
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
        ($this->recorder)([$canonical], $user);

        return $importRunId;
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
