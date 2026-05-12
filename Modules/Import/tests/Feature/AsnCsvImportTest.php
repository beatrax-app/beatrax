<?php

declare(strict_types=1);

use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportConfirmResult;
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
    );

    expect($result)->toBeInstanceOf(ImportConfirmResult::class);
    expect($result->inserted)->toBeGreaterThan(0);
    expect($result->duplicates)->toBe(0);
    expect(Transaction::count())->toBe($result->inserted);
});

it('returns zero new rows when re-importing the same file', function (): void {
    $fixture = __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv';

    $first = $this->importer->runAndConfirm($fixture, 'asn-csv', $this->fixtureUser);
    $second = $this->importer->runAndConfirm($fixture, 'asn-csv', $this->fixtureUser);

    expect($first->inserted)->toBeGreaterThan(0);
    expect($second->inserted)->toBe(0);
    expect($second->duplicates)->toBe($first->inserted);
    expect(Transaction::count())->toBe($first->inserted);
});

it('returns mixed inserted/duplicates when an overlapping period is re-imported', function (): void {
    $monthA = __DIR__.'/../../../../tests/fixtures/asn-month-a.csv';
    $monthAandB = __DIR__.'/../../../../tests/fixtures/asn-month-a-and-b.csv';

    $first = $this->importer->runAndConfirm($monthA, 'asn-csv', $this->fixtureUser);
    $second = $this->importer->runAndConfirm($monthAandB, 'asn-csv', $this->fixtureUser);

    expect($first->inserted)->toBeGreaterThan(0);
    expect($second->inserted)->toBeGreaterThan(0);
    expect($second->duplicates)->toBeGreaterThan(0);
    expect($second->inserted)->toBeLessThan($first->inserted);
});

it('substitutes the no-counterparty sentinel on rows with an empty Naam column', function (): void {
    $fixture = __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv';

    $first = $this->importer->runAndConfirm($fixture, 'asn-csv', $this->fixtureUser);
    $sentinelRows = Transaction::query()->where('counterparty_normalized', '_no_counterparty')->count();

    // The gold fixture contains at least one nameless BEA row, so the
    // sentinel landing path must produce a positive count. Also assert the
    // sentinel rows are a real subset of the inserted set so a mis-count
    // from a different counterparty cannot satisfy the test.
    expect($sentinelRows)->toBeGreaterThan(0);
    expect($sentinelRows)->toBeLessThanOrEqual($first->inserted);
});
