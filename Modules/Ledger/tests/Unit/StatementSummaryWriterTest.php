<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\StatementSummary;
use Modules\Ledger\Public\Contracts\RecordsStatementSummary;
use Modules\Ledger\Public\Dto\StatementSummaryData;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureUser = $seeded['user'];
    $this->fixtureAccount = $seeded['account'];

    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $this->fixtureUser->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/fixture.xml',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => 'previewed',
    ]);
    $this->importRun = $run;

    $this->writer = $this->app->make(RecordsStatementSummary::class);
});

it('inserts a statement_summaries row for an import run', function (): void {
    ($this->writer)($this->fixtureUser, new StatementSummaryData(
        importRunId: $this->importRun->id,
        accountId: $this->fixtureAccount->id,
        ibanOwner: 'NL57ASNB0123456789',
        statementNumber: '2026/04',
        periodStart: CarbonImmutable::parse('2026-04-01 00:00:00'),
        periodEnd: CarbonImmutable::parse('2026-04-30 23:59:59'),
        openingBalanceMinor: 100000,
        openingBalanceCurrency: 'EUR',
        openingBalanceDate: CarbonImmutable::parse('2026-04-01 00:00:00'),
        closingBalanceMinor: 120000,
        closingBalanceCurrency: 'EUR',
        closingBalanceDate: CarbonImmutable::parse('2026-04-30 00:00:00'),
        entryCount: 14,
        extras: ['subVersion' => '001.02'],
    ));

    /** @var StatementSummary|null $row */
    $row = StatementSummary::query()->where('import_run_id', $this->importRun->id)->first();
    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($this->fixtureUser->id);
    expect($row->account_id)->toBe($this->fixtureAccount->id);
    expect($row->opening_balance_minor)->toBe(100000);
    expect($row->closing_balance_minor)->toBe(120000);
    expect($row->entry_count)->toBe(14);
    expect($row->iban_owner)->toBe('NL57ASNB0123456789');
    expect($row->statement_number)->toBe('2026/04');
    expect($row->extras)->toBe(['subVersion' => '001.02']);
})->group('phase-2');

it('upserts on (user_id, import_run_id) — a second call updates the row in place', function (): void {
    ($this->writer)($this->fixtureUser, new StatementSummaryData(
        importRunId: $this->importRun->id,
        accountId: $this->fixtureAccount->id,
        ibanOwner: 'NL57ASNB0123456789',
        statementNumber: '2026/04',
        periodStart: null,
        periodEnd: null,
        openingBalanceMinor: 100000,
        openingBalanceCurrency: 'EUR',
        openingBalanceDate: null,
        closingBalanceMinor: 120000,
        closingBalanceCurrency: 'EUR',
        closingBalanceDate: null,
        entryCount: 14,
    ));

    ($this->writer)($this->fixtureUser, new StatementSummaryData(
        importRunId: $this->importRun->id,
        accountId: $this->fixtureAccount->id,
        ibanOwner: 'NL57ASNB0123456789',
        statementNumber: '2026/04-updated',
        periodStart: null,
        periodEnd: null,
        openingBalanceMinor: 200000,
        openingBalanceCurrency: 'EUR',
        openingBalanceDate: null,
        closingBalanceMinor: 250000,
        closingBalanceCurrency: 'EUR',
        closingBalanceDate: null,
        entryCount: 28,
    ));

    expect(StatementSummary::query()->where('import_run_id', $this->importRun->id)->count())->toBe(1);
    /** @var StatementSummary $row */
    $row = StatementSummary::query()->where('import_run_id', $this->importRun->id)->firstOrFail();
    expect($row->statement_number)->toBe('2026/04-updated');
    expect($row->opening_balance_minor)->toBe(200000);
    expect($row->closing_balance_minor)->toBe(250000);
    expect($row->entry_count)->toBe(28);
})->group('phase-2');
