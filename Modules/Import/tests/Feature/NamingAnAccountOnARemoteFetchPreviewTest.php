<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Models\Account;

uses(RefreshDatabase::class);

const UNNAMED_REMOTE_IBAN = 'NL91RABO0417164300';

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);
});

/**
 * @return Generator<int, SourceTransactionDto>
 */
function rowsForAnUnnamedRemoteAccount(): Generator
{
    yield new SourceTransactionDto(
        bookedAt: CarbonImmutable::parse('2026-06-01'),
        postedAt: CarbonImmutable::parse('2026-06-01'),
        valueDate: CarbonImmutable::parse('2026-06-01'),
        ownIban: UNNAMED_REMOTE_IBAN,
        counterpartyIban: 'NL00BANK0000000001',
        counterpartyName: 'Acme Groceries',
        currency: 'EUR',
        amountMinor: -1234,
        sourceRef: 'tx-1',
        description: 'Weekly groceries',
        rawPayload: ['enable_banking' => ['transaction_id' => 'tx-1']],
        sourceRowIndex: 0,
    );
}

it('names an account on a preview the bank was fetched into, and re-reads the window', function (): void {
    $key = hash('sha256', 'open-banking:test-institution:1:2026-06-01:2026-06-30');

    $preview = $this->importer->runFromRemoteFetch(
        rowsForAnUnnamedRemoteAccount(),
        'enable-banking',
        $this->fixtureUser,
        $key,
    );

    expect($preview->accountsToName)->toHaveCount(1);

    Livewire::test(PreviewWizard::class, ['id' => $preview->importRunId])
        ->call('nameAccount', UNNAMED_REMOTE_IBAN, 'Rabobank current')
        ->assertHasNoErrors();

    expect(Account::query()->where('iban', UNNAMED_REMOTE_IBAN)->exists())->toBeTrue();
});
