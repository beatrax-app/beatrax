<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Forecasting\Public\Services\NetWorthQuery;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\TransactionType;

// The two fixtures are the same 41 movements seen from both sides: every
// PayPal funding leg has the ASN direct debit that settled it, same day, same
// amount. Read alone the PayPal file balances to zero, which is why the
// funding legs could be dropped for a release without any single-file test
// noticing.
beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    /** @var array{user: User} $seed */
    $seed = $this->seedFixtureUserAndAccount();
    $this->user = $seed['user'];
    $this->actingAs($this->user);
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($this->user);

    $this->importer = $this->app->make(RunsImports::class);
});

function importBothSides(User $user): void
{
    /** @var RunsImports $importer */
    $importer = test()->importer;

    $importer->runAndConfirm(
        base_path('Modules/Ingestion/tests/fixtures/paypal/paypal-sample-1.csv'),
        'paypal-csv',
        $user,
        'paypal-sample-1.csv',
    );
    $importer->runAndConfirm(
        base_path('tests/fixtures/asn-paypal-funding-2026-04-05.csv'),
        'asn-csv',
        $user,
        'asn-paypal-funding-2026-04-05.csv',
        BankCsvFormatHint::Asn,
    );
}

it('reports the funding total once, not twice, across both statements', function (): void {
    importBothSides($this->user);

    /** @var NetWorthQuery $netWorth */
    $netWorth = $this->app->make(NetWorthQuery::class);

    expect($netWorth->forUser($this->user)->totalMinor)->toBe(-55408);
});

it('emits the PayPal side of every funding leg as a transfer_in', function (): void {
    importBothSides($this->user);

    $fundingLegs = Transaction::query()
        ->where('user_id', $this->user->id)
        ->where('type', TransactionType::TransferIn->value)
        ->get();

    expect($fundingLegs)->toHaveCount(41);
    expect($fundingLegs->sum('amount_minor'))->toBe(55408);
});

it('pairs every funding leg against the bank debit that settled it', function (): void {
    importBothSides($this->user);

    $unpaired = Transaction::query()
        ->where('user_id', $this->user->id)
        ->whereIn('type', TransactionType::transferValues())
        ->whereNull('pair_transaction_id')
        ->count();

    expect($unpaired)->toBe(0);
});
