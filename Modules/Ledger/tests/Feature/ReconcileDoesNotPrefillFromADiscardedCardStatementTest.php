<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Import\Public\Actions\DiscardImport;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Contracts\RecordsStatementSummary;
use Modules\Ledger\Public\Dto\StatementSummaryData;
use Modules\Ledger\Public\Enums\ImportRunStatus;

// The statement-summary branch takes confirmed runs only, because
// ImportPipeline writes the summary while building the PREVIEW. The card
// branch reads card_statements, which CardStatementUpserter fills from every
// ICS summary — so a discarded file still named the figure Complete locks to.

function icsCardFixture(User $user, string $suffix): Account
{
    return Account::create([
        'user_id' => $user->id,
        'name' => 'ICS '.$suffix,
        'slug' => 'ics-discard-'.$suffix,
        'kind' => 'ics_card',
        'iban' => 'NL00ICS'.str_pad($suffix, 11, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
    ]);
}

// The shape ImportPipeline::persistStatementMetadata() writes at preview time,
// through the same contract it resolves.
function recordIcsStatementSummary(User $user, int $runId, int $accountId, int $closingMinor): void
{
    app(RecordsStatementSummary::class)($user, new StatementSummaryData(
        importRunId: $runId,
        accountId: $accountId,
        ibanOwner: 'NL00ICS00000000001',
        statementNumber: '2026-06',
        periodStart: CarbonImmutable::parse('2026-06-16'),
        periodEnd: CarbonImmutable::parse('2026-07-15'),
        openingBalanceMinor: -60696,
        openingBalanceCurrency: 'EUR',
        openingBalanceDate: CarbonImmutable::parse('2026-06-16'),
        closingBalanceMinor: $closingMinor,
        closingBalanceCurrency: 'EUR',
        closingBalanceDate: CarbonImmutable::parse('2026-07-15'),
        entryCount: 12,
    ));
}

beforeEach(function (): void {
    $this->user = User::create(['username' => 'ics-discard-fixture', 'password' => 'fixture-password', 'period_start_day' => 1]);
});

it('does not prefill the reconcile statement balance from a discarded card statement', function (): void {
    $card = icsCardFixture($this->user, '1');
    $run = $this->makeImportRun($this->user, str_repeat('c', 64));

    recordIcsStatementSummary($this->user, (int) $run->id, (int) $card->id, -141650);
    app(DiscardImport::class)((int) $run->id, $this->user);

    // The healing pass ResolveChainLinksJob runs at the top of every chain
    // resolution, which ConfirmImport dispatches for any later import.
    app(UpsertsCardStatements::class)->upsertForUser($this->user);

    Livewire::actingAs($this->user)
        ->test(ReconcilePage::class, ['accountId' => $card->id])
        ->assertSet('statementBalance', '');
});

it('still prefills from a card statement whose import was confirmed', function (): void {
    $card = icsCardFixture($this->user, '2');
    $run = $this->makeImportRun($this->user, str_repeat('d', 64));

    recordIcsStatementSummary($this->user, (int) $run->id, (int) $card->id, -141650);
    $run->update(['status' => ImportRunStatus::Confirmed->value]);

    app(UpsertsCardStatements::class)->upsertForImportRun((int) $run->id, $this->user);

    Livewire::actingAs($this->user)
        ->test(ReconcilePage::class, ['accountId' => $card->id])
        ->assertSet('statementBalance', '-1,416.50')
        ->assertSet('statementDate', '2026-07-15');
});
