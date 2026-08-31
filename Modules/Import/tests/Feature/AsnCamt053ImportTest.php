<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\StatementSummary;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);
});

it('imports an ASN CAMT.053 XML end-to-end with EndToEndId populated as source_ref', function (): void {
    $result = $this->importer->runAndConfirm(
        __DIR__.'/../../../../tests/fixtures/asn-camt053-sample-1.xml',
        'camt053',
        $this->fixtureUser,
    );

    expect($result)->toBeInstanceOf(ImportConfirmResult::class);
    expect($result->inserted)->toBeGreaterThan(0);
    expect($result->errors)->toBe(0);

    $totalRows = Transaction::query()
        ->where('source_format', 'camt053')
        ->where('import_run_id', $result->importRunId)
        ->count();
    expect($totalRows)->toBe($result->inserted);

    $withSourceRef = Transaction::query()
        ->where('source_format', 'camt053')
        ->whereNotNull('source_ref')
        ->count();
    expect($withSourceRef)->toBeGreaterThan(0);

    /** @var StatementSummary|null $summary */
    $summary = StatementSummary::query()
        ->where('import_run_id', $result->importRunId)
        ->first();
    expect($summary)->not->toBeNull();
    expect($summary->opening_balance_minor)->toBeInt();
    expect($summary->closing_balance_minor)->toBeInt();
    expect($summary->entry_count)->toBeGreaterThan(0);
    expect($summary->iban_owner)->toBe('NL57ASNB0123456789');
    expect($summary->opening_balance_currency)->toBe('EUR');
    expect($summary->closing_balance_currency)->toBe('EUR');
})->group('phase-2');

it('re-importing the same CAMT.053 file is a no-op (idempotent SHA-256 short-circuit)', function (): void {
    $fixture = __DIR__.'/../../../../tests/fixtures/asn-camt053-sample-1.xml';
    $first = $this->importer->runAndConfirm($fixture, 'camt053', $this->fixtureUser);
    $second = $this->importer->runAndConfirm($fixture, 'camt053', $this->fixtureUser);

    expect($first->inserted)->toBeGreaterThan(0);
    expect($second->inserted)->toBe(0);
    expect(Transaction::query()->where('source_format', 'camt053')->count())->toBe($first->inserted);
})->group('phase-2');

it('does not create a statement_summaries row when the same user imports a CSV', function (): void {
    $result = $this->importer->runAndConfirm(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        formatHint: BankCsvFormatHint::Asn,
    );

    expect($result->inserted)->toBeGreaterThan(0);
    expect(StatementSummary::query()->where('import_run_id', $result->importRunId)->count())->toBe(0);
})->group('phase-2');
