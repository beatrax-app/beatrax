<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportConfirmResult;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);
});

it('imports every parsed row from the gold fixture on the first run', function (): void {
    $result = $this->importer->runAndConfirm(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        formatHint: BankCsvFormatHint::Asn,
    );

    expect($result)->toBeInstanceOf(ImportConfirmResult::class);
    expect($result->inserted)->toBeGreaterThan(0);
    expect($result->duplicates)->toBe(0);
    expect(Transaction::count())->toBe($result->inserted);
});

it('returns zero new rows when re-importing the same file', function (): void {
    $fixture = __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv';

    $first = $this->importer->runAndConfirm($fixture, 'asn-csv', $this->fixtureUser, formatHint: BankCsvFormatHint::Asn);
    $second = $this->importer->runAndConfirm($fixture, 'asn-csv', $this->fixtureUser, formatHint: BankCsvFormatHint::Asn);

    expect($first->inserted)->toBeGreaterThan(0);
    expect($second->inserted)->toBe(0);
    expect($second->duplicates)->toBe($first->inserted);
    expect(Transaction::count())->toBe($first->inserted);
});

it('returns mixed inserted/duplicates when an overlapping period is re-imported', function (): void {
    $monthA = __DIR__.'/../../../../tests/fixtures/asn-month-a.csv';
    $monthAandB = __DIR__.'/../../../../tests/fixtures/asn-month-a-and-b.csv';

    $first = $this->importer->runAndConfirm($monthA, 'asn-csv', $this->fixtureUser, formatHint: BankCsvFormatHint::Asn);
    $second = $this->importer->runAndConfirm($monthAandB, 'asn-csv', $this->fixtureUser, formatHint: BankCsvFormatHint::Asn);

    expect($first->inserted)->toBeGreaterThan(0);
    expect($second->inserted)->toBeGreaterThan(0);
    expect($second->duplicates)->toBeGreaterThan(0);
    expect($second->inserted)->toBeLessThan($first->inserted);
});

it('refreshes import_runs.source_format when reusing an existing row', function (): void {
    $fixture = __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv';
    $sha = hash_file('sha256', $fixture);
    expect($sha)->toBeString();

    /** @var ImportRun $stale */
    $stale = ImportRun::create([
        'user_id' => $this->fixtureUser->id,
        'source_format' => 'legacy',
        'raw_file_path' => '/tmp/legacy.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::parse('2026-04-01 12:00:00'),
        'status' => 'discarded',
    ]);

    $preview = $this->importer->runFromUpload(
        $fixture,
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    expect($preview->importRunId)->toBe($stale->id);
    expect(ImportRun::query()->find($stale->id)?->source_format)->toBe('asn-csv');
});

it('substitutes the no-counterparty sentinel on rows with an empty Naam column', function (): void {
    $fixture = __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv';

    $first = $this->importer->runAndConfirm($fixture, 'asn-csv', $this->fixtureUser, formatHint: BankCsvFormatHint::Asn);
    $sentinelRows = Transaction::query()->where('counterparty_normalized', '_no_counterparty')->count();

    // The gold fixture carries at least one nameless BEA row. The subset
    // assertion stops a mis-count from another counterparty satisfying the test.
    expect($sentinelRows)->toBeGreaterThan(0);
    expect($sentinelRows)->toBeLessThanOrEqual($first->inserted);
});
