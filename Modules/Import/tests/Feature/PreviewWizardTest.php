<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

it('renders status badges (New / Duplicate) for parsed rows', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertSee('New')
        ->assertSee('Confirm import')
        ->assertSee('Discard import');
});

it('renders an inline unknown-IBAN prompt when accounts are not yet named', function (): void {
    // Drop the seeded fixture account so the ASN fixture IBAN is unknown
    Account::query()->where('iban', 'NL57ASNB0123456789')->delete();

    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->assertSee('We found an unfamiliar IBAN');
});

it('confirms an import and redirects to the results page', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    expect(Transaction::count())->toBe(0);

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->call('confirm')
        ->assertRedirect();

    expect(Transaction::count())->toBeGreaterThan(0);
    expect(ImportRun::query()->find($preview->importRunId)?->status)->toBe('confirmed');
});

it('dispatches DetectRecurringSeriesJob alongside ResolveChainLinksJob synchronously after a successful confirm', function (): void {
    // Both dispatchers use ::dispatchSync so the decrypt-requiring resolvers
    // run in-process with this request's KEK. That never reaches the queue
    // layer, so Queue::fake would see nothing — hence Bus::fake.
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    Bus::fake();

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->call('confirm')
        ->assertRedirect();

    Bus::assertDispatchedSync(
        ResolveChainLinksJob::class,
        fn (ResolveChainLinksJob $job): bool => $job->userId === $this->fixtureUser->id,
    );
    Bus::assertDispatchedSync(
        DetectRecurringSeriesJob::class,
        fn (DetectRecurringSeriesJob $job): bool => $job->userId === $this->fixtureUser->id,
    );
});

it('does NOT dispatch DetectRecurringSeriesJob on a re-confirm with zero new work', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->call('confirm')
        ->assertRedirect();

    // Faked only now, so the first confirm's own dispatches are not counted.
    Bus::fake();

    /** @var ConfirmsImports $confirmer */
    $confirmer = $this->app->make(ConfirmsImports::class);
    ($confirmer)($preview->importRunId, $this->fixtureUser);

    Bus::assertNotDispatched(ResolveChainLinksJob::class);
    Bus::assertNotDispatched(DetectRecurringSeriesJob::class);
});

it('discards an import and redirects back to /imports/new', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->call('discard')
        ->assertRedirect();

    expect(ImportRun::query()->find($preview->importRunId)?->status)->toBe('discarded');
    expect(Transaction::count())->toBe(0);
});

it('cross-user import access is blocked', function (): void {
    /** @var User $otherUser */
    $otherUser = User::create([
        'username' => 'attacker',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    $this->actingAs($otherUser);

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->call('confirm');
})->throws(ModelNotFoundException::class);

it('returns inserted_count plus duplicate_count when an already-confirmed run is re-confirmed', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $firstResult = $importer->runAndConfirm(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    /** @var ImportRun $run */
    $run = ImportRun::query()->findOrFail($firstResult->importRunId);
    expect($run->status)->toBe('confirmed');
    // The fixture's first run inserts every row as new, leaving duplicates at
    // 0 — nothing for the re-confirm's sum to distinguish itself with.
    $run->update(['duplicate_count' => 7]);
    $expectedDuplicates = $run->inserted_count + 7;

    /** @var ConfirmsImports $confirmer */
    $confirmer = $this->app->make(ConfirmsImports::class);
    $second = ($confirmer)($firstResult->importRunId, $this->fixtureUser);

    expect($second->inserted)->toBe(0);
    expect($second->duplicates)->toBe($expectedDuplicates);
    expect($second->enriched)->toBe($run->enriched_count);
    expect($second->errors)->toBe(0);
});

it('renders the canonical results summary on the results page', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $result = $importer->runAndConfirm(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    $response = $this->get('/imports/'.$result->importRunId);

    $response->assertOk();
    $response->assertSee('Imported', false);
    $response->assertSee('skipped', false);
    $response->assertSee('duplicates', false);
});
