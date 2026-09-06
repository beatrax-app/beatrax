<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\StoredCopy;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Services\TransactionStatusWriter;
use Modules\Migration\Internal\Actions\CheckForUpdates;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Enums\MigrationEntityType;
use Modules\Migration\Internal\Enums\MigrationSourceProduct;
use Modules\Migration\Internal\Enums\UnmappedItemType;
use Modules\Migration\Internal\Pipeline\EntityChangeApplier;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

// A YNAB4 row's identity is its account, date, payee and category; its
// fingerprint here is the account, dates, amount and counterparty. Recategorise
// a transaction in the old app and re-export — an ordinary tidy-up before
// migrating — and the row arrives under an identity this device has never
// seen, carrying the fingerprint of a row it already holds. RecordsTransactions
// dedupes onto the row that exists, and the promotion then re-applies the
// source's cleared flag to it. On a row the reader reconciled against a bank
// statement by hand, that is a file overruling them, silently.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'migration-reconciled-lock-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);
});

function importYnab4Export(string $fixture): int
{
    $run = app(StartMigrationRun::class)->__invoke(
        test()->user,
        MigrationSourceProduct::Ynab4->value,
        MigrationFixturePaths::ynab4Dir($fixture),
        'Beatrax Budget.zip',
    );
    app(ConfirmMigration::class)->__invoke($run->id, test()->user);

    return $run->id;
}

function promotedTransactionId(int $runId, string $postedDate, int $amountMinor): int
{
    /** @var object $staged */
    $staged = test()->db->connection()->table('migration_staging_transactions')
        ->where('user_id', test()->user->id)
        ->where('migration_run_id', $runId)
        ->whereNull('parent_source_external_id')
        ->whereDate('posted_at', $postedDate)
        ->where('amount_minor', $amountMinor)
        ->firstOrFail(['source_external_id']);

    return (int) test()->db->connection()->table('migration_source_map')
        ->where('user_id', test()->user->id)
        ->where('source_entity_type', MigrationEntityType::Transaction->value)
        ->where('source_external_id', (string) $staged->source_external_id)
        ->value('beatrax_id');
}

function statusOfPromotedRow(int $transactionId): string
{
    return (string) test()->db->connection()->table('transactions')
        ->where('id', $transactionId)
        ->value('status');
}

function sourceExternalIdOfPromotedRow(int $runId, string $postedDate, int $amountMinor): string
{
    /** @var object $staged */
    $staged = test()->db->connection()->table('migration_staging_transactions')
        ->where('user_id', test()->user->id)
        ->where('migration_run_id', $runId)
        ->whereNull('parent_source_external_id')
        ->whereDate('posted_at', $postedDate)
        ->where('amount_minor', $amountMinor)
        ->firstOrFail(['source_external_id']);

    return (string) $staged->source_external_id;
}

/** @return array{description: string, amountMinor: int} */
function ledgerCopyOfPromotedRow(int $transactionId): array
{
    /** @var object $row */
    $row = test()->db->connection()->table('transactions')
        ->where('id', $transactionId)
        ->firstOrFail(['description', 'amount_minor']);

    return ['description' => (string) $row->description, 'amountMinor' => (int) $row->amount_minor];
}

/** @return list<string> the reasons this run recorded against the reconciled-lock key */
function reconciledLockRefusalsIn(int $runId): array
{
    return test()->db->connection()->table('migration_staging_unmapped_items')
        ->where('user_id', test()->user->id)
        ->where('migration_run_id', $runId)
        ->where('item_type', UnmappedItemType::Extra->value)
        ->get()
        ->filter(static fn (object $row): bool => StoredCopy::names(
            is_string($row->reason) ? $row->reason : null,
            'migration::unmapped.reason.reconciled_status_kept',
        ))
        ->map(static fn (object $row): string => StoredCopy::read((string) $row->reason))
        ->values()
        ->all();
}

function lockTheJanuaryStatement(int $transactionId): int
{
    $accountId = (int) test()->db->connection()->table('transactions')
        ->where('id', $transactionId)
        ->value('account_id');

    // Locked through the flow the reader would use, not by writing the column:
    // a fixture that stamps 'reconciled' by hand proves the guard sees a value,
    // not that the reconcile path can produce one this import then walks back.
    return app(TransactionStatusWriter::class)->reconcileClearedUpTo(
        test()->user,
        $accountId,
        CarbonImmutable::parse('2026-01-15'),
    );
}

it('re-states the row it already imported rather than adding a second copy of it', function (): void {
    $firstRun = importYnab4Export('v1');
    $groceryTxId = promotedTransactionId($firstRun, '2026-01-15', -4500);

    $before = $this->db->connection()->table('transactions')->where('user_id', $this->user->id)->count();

    $result = app(ConfirmMigration::class)->__invoke(
        app(CheckForUpdates::class)->__invoke(
            $firstRun,
            $this->user,
            MigrationSourceProduct::Ynab4->value,
            MigrationFixturePaths::ynab4Dir('recategorised'),
        )->id,
        $this->user,
    );

    // The category the reader moved the row to is not applied here — a second
    // import never overwrites local state on sight — but the row is now known
    // under its new identity, so the next update run can diff it like any
    // other change. What must not happen is what used to: a second €45 Albert
    // Heijn on the 15th, and a balance €45 short.
    expect($this->db->connection()->table('transactions')->where('user_id', $this->user->id)->count())->toBe($before)
        ->and($result->transactionsInserted)->toBe(0)
        ->and($this->db->connection()->table('transactions')
            ->where('user_id', $this->user->id)
            ->whereDate('posted_at', '2026-01-15')
            ->where('amount_minor', -4500)
            ->count())->toBe(1)
        ->and($groceryTxId)->toBeGreaterThan(0);
});

it('leaves a reconciled row reconciled when a recategorised export re-states it, and names it in the run', function (): void {
    $firstRun = importYnab4Export('v1');
    $groceryTxId = promotedTransactionId($firstRun, '2026-01-15', -4500);

    expect(lockTheJanuaryStatement($groceryTxId))->toBeGreaterThan(0)
        ->and(statusOfPromotedRow($groceryTxId))->toBe(ClearedStatus::Reconciled->value);

    $secondRun = importYnab4Export('recategorised');

    expect(statusOfPromotedRow($groceryTxId))->toBe(ClearedStatus::Reconciled->value);

    $refusals = reconciledLockRefusalsIn($secondRun);

    expect($refusals)->toHaveCount(1)
        ->and($refusals[0])->toContain('reconciled');
});

it('still carries the source flag across for a row the reader never reconciled', function (): void {
    $firstRun = importYnab4Export('v1');
    $groceryTxId = promotedTransactionId($firstRun, '2026-01-15', -4500);

    $this->db->connection()->table('transactions')
        ->where('id', $groceryTxId)
        ->update(['status' => ClearedStatus::Uncleared->value]);

    $secondRun = importYnab4Export('recategorised');

    // The counter-case: without it the guard could refuse every re-stamp and
    // the case above would still read as "the lock held".
    expect(statusOfPromotedRow($groceryTxId))->toBe(ClearedStatus::Cleared->value)
        ->and(reconciledLockRefusalsIn($secondRun))->toBe([]);
});

// The staged status was refused and the rest of the row was not: a re-run could
// still restate the description, and the correction screen could still move the
// amount, on a row the reader had matched against a statement by hand. Both
// went through EntityChangeApplier, which asked nothing about the lock.
it('leaves a reconciled row\'s description and amount as the reader left them', function (): void {
    $firstRun = importYnab4Export('v1');
    $groceryTxId = promotedTransactionId($firstRun, '2026-01-15', -4500);
    $sourceExternalId = sourceExternalIdOfPromotedRow($firstRun, '2026-01-15', -4500);

    expect(lockTheJanuaryStatement($groceryTxId))->toBeGreaterThan(0)
        ->and(statusOfPromotedRow($groceryTxId))->toBe(ClearedStatus::Reconciled->value);

    $before = ledgerCopyOfPromotedRow($groceryTxId);
    $applier = app(EntityChangeApplier::class);

    expect($applier->apply(
        $this->user,
        MigrationSourceProduct::Ynab4->value,
        MigrationEntityType::Transaction->value,
        $sourceExternalId,
        ['description' => 'Renamed by a later export'],
    ))->toBeFalse()
        ->and($applier->applyTransactionAmount($this->user, $groceryTxId, -5500))->toBeFalse()
        ->and(ledgerCopyOfPromotedRow($groceryTxId))->toBe($before);

    // The counter-case: a refusal that also refuses an unlocked row says
    // nothing about the lock, and both writes have to land once it is off.
    app(TransactionStatusWriter::class)->unreconcile($this->user, $groceryTxId);

    expect($applier->apply(
        $this->user,
        MigrationSourceProduct::Ynab4->value,
        MigrationEntityType::Transaction->value,
        $sourceExternalId,
        ['description' => 'Renamed by a later export'],
    ))->toBeTrue()
        ->and($applier->applyTransactionAmount($this->user, $groceryTxId, -5500))->toBeTrue()
        ->and(ledgerCopyOfPromotedRow($groceryTxId))->toBe([
            'description' => 'Renamed by a later export',
            'amountMinor' => -5500,
        ]);
});
