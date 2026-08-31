<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Import\Public\Actions\DiscardImport;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Models\Account;

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
});

uses(RefreshDatabase::class);

it('does not prefill the reconcile statement balance from a discarded statement', function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);

    $importer = $this->app->make(RunsImports::class);

    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-camt053-sample-1.xml',
        'camt053',
        $this->fixtureUser,
        'asn-camt053-sample-1.xml',
    );

    $this->app->make(DiscardImport::class)($preview->importRunId, $this->fixtureUser);

    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();

    Livewire::test(ReconcilePage::class, ['accountId' => $account->id])
        ->assertSet('statementBalance', '');
});
