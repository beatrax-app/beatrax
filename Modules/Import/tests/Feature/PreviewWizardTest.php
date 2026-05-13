<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

beforeEach(function (): void {
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
    );

    expect(Transaction::count())->toBe(0);

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->call('confirm')
        ->assertRedirect();

    expect(Transaction::count())->toBeGreaterThan(0);
    expect(ImportRun::query()->find($preview->importRunId)?->status)->toBe('confirmed');
});

it('discards an import and redirects back to /imports/new', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
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
        'email' => 'attacker@diederik.test',
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
    );

    $this->actingAs($otherUser);

    // Confirming someone else's run is a 404 via firstOrFail
    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->call('confirm');
})->throws(ModelNotFoundException::class);

it('renders the canonical results summary on the results page', function (): void {
    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $result = $importer->runAndConfirm(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $this->fixtureUser,
        'asn-sample-1.csv',
    );

    $response = $this->get('/imports/'.$result->importRunId);

    $response->assertOk();
    $response->assertSee('Imported', false);
    $response->assertSee('skipped', false);
    $response->assertSee('duplicates', false);
});
