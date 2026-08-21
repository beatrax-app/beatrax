<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Http\Livewire\OpeningBalanceEditor;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Actions\SetAccountOpeningBalance;
use Modules\Forecasting\Public\Exceptions\OpeningBalanceDivergenceWarning;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

function obeUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function obeAccount(User $user, string $kind, string $slug, ?int $openingMinor = null, ?string $asOfDate = null): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'obe '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => 'OBE-'.strtoupper($slug),
        'default_currency' => 'EUR',
        'opening_balance_minor' => $openingMinor,
        'opening_balance_as_of_date' => $asOfDate,
    ]);
}

function obeImportRun(User $user): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/obe.csv',
        'sha256' => str_repeat('o', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
}

function obeTxn(User $user, Account $account, ImportRun $run, int $amountMinor, string $bookedAt, int $row): Transaction
{
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $amountMinor < 0 ? 'expense' : 'income',
        'posted_at' => substr($bookedAt, 0, 10),
        'booked_at' => $bookedAt,
        'value_date' => substr($bookedAt, 0, 10),
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'obe txn',
        'counterparty_normalized' => 'obe txn',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $row,
        'fingerprint' => str_pad('obe-'.$row, 64, 'b', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

beforeEach(function (): void {
    $this->user = obeUser('obe');
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('mounts with stored opening balance + as-of-date pre-populated', function (): void {
    $account = obeAccount($this->user, 'paypal', 'paypal-1', openingMinor: 12500, asOfDate: '2026-05-01');

    Livewire::test(OpeningBalanceEditor::class, [
        'accountId' => $account->id,
        'accountName' => 'My PayPal',
        'accountKind' => 'paypal',
        'currentOpeningMinor' => 12500,
        'currentAsOfDate' => '2026-05-01',
        'currency' => 'EUR',
    ])
        ->assertSet('openingInput', '125,00')
        ->assertSet('asOfInput', '2026-05-01');
});

it('mounts with empty inputs when no opening balance is stored', function (): void {
    $account = obeAccount($this->user, 'paypal', 'paypal-2');

    Livewire::test(OpeningBalanceEditor::class, [
        'accountId' => $account->id,
        'accountName' => 'Fresh PayPal',
        'accountKind' => 'paypal',
        'currentOpeningMinor' => null,
        'currentAsOfDate' => null,
        'currency' => 'EUR',
    ])
        ->assertSet('openingInput', '')
        ->assertSet('asOfInput', '');
});

it('raises a validation error when saving with missing as-of-date', function (): void {
    $account = obeAccount($this->user, 'paypal', 'paypal-3');

    Livewire::test(OpeningBalanceEditor::class, [
        'accountId' => $account->id,
        'accountName' => 'PP',
        'accountKind' => 'paypal',
        'currentOpeningMinor' => null,
        'currentAsOfDate' => null,
        'currency' => 'EUR',
    ])
        ->set('openingInput', '500,00')
        ->set('asOfInput', '')
        ->call('save')
        ->assertSet('errorMessage', 'Pick the date this opening balance applies to.');
});

it('pops the soft-warning banner when the entered value diverges by more than €500', function (): void {
    Bus::fake();
    CarbonImmutable::setTestNow('2026-05-01 12:00:00');

    $account = obeAccount($this->user, 'paypal', 'paypal-4');
    $run = obeImportRun($this->user);
    // The account sums to -10000 and the user enters 100000, so the divergence
    // is 110000 — far past the 50000 warning threshold.
    obeTxn($this->user, $account, $run, -10000, '2026-04-15 09:00:00', 1);

    Livewire::test(OpeningBalanceEditor::class, [
        'accountId' => $account->id,
        'accountName' => 'PP',
        'accountKind' => 'paypal',
        'currentOpeningMinor' => null,
        'currentAsOfDate' => null,
        'currency' => 'EUR',
    ])
        ->set('openingInput', '1000,00')
        ->set('asOfInput', '2026-05-01')
        ->call('save')
        ->assertSet('showingDivergenceBanner', true)
        ->assertSet('divergenceDiffMinor', 110000)
        ->assertSet('beatraxsNumberMinor', -10000);
});

it('populates input with beatraxs number when clicked', function (): void {
    $account = obeAccount($this->user, 'paypal', 'paypal-5');

    Livewire::test(OpeningBalanceEditor::class, [
        'accountId' => $account->id,
        'accountName' => 'PP',
        'accountKind' => 'paypal',
        'currentOpeningMinor' => null,
        'currentAsOfDate' => null,
        'currency' => 'EUR',
    ])
        ->set('showingDivergenceBanner', true)
        ->set('beatraxsNumberMinor', -2500)
        ->call('useBeatraxsNumber')
        ->assertSet('openingInput', '-25,00')
        ->assertSet('showingDivergenceBanner', false)
        ->assertSet('divergenceDiffMinor', null);
});

it('commits with allowDivergence=true when useMyNumber is clicked', function (): void {
    Bus::fake();
    CarbonImmutable::setTestNow('2026-05-01 12:00:00');

    $account = obeAccount($this->user, 'paypal', 'paypal-6');
    $run = obeImportRun($this->user);
    obeTxn($this->user, $account, $run, -10000, '2026-04-15 09:00:00', 1);

    Livewire::test(OpeningBalanceEditor::class, [
        'accountId' => $account->id,
        'accountName' => 'PP',
        'accountKind' => 'paypal',
        'currentOpeningMinor' => null,
        'currentAsOfDate' => null,
        'currency' => 'EUR',
    ])
        ->set('openingInput', '1000,00')
        ->set('asOfInput', '2026-05-01')
        ->call('useMyNumber')
        ->assertSet('showingDivergenceBanner', false)
        ->assertSet('currentOpeningMinor', 100000)
        ->assertSet('saved', true);

    $row = Account::query()->find($account->id);
    expect($row?->opening_balance_minor)->toBe(100000);
});

it('refuses a cross-user account at the Public Action layer (cross-user 404 contract)', function (): void {
    // The Livewire harness does not propagate a mount-time
    // NotFoundHttpException through ->toThrow(), so the Public Action carries
    // the assertion instead; the SFC still raises it over real HTTP.
    $other = obeUser('other');
    $foreign = obeAccount($other, 'paypal', 'foreign-paypal');

    $action = app(SetAccountOpeningBalance::class);

    expect(fn () => ($action)($foreign->id, $this->user, 5000, '2026-05-01', allowDivergence: true))
        ->toThrow(NotFoundHttpException::class);
});

it('dispatches three ProjectForecastJob horizons after successful save', function (): void {
    Bus::fake();
    CarbonImmutable::setTestNow('2026-05-01 12:00:00');

    $account = obeAccount($this->user, 'bank', 'asn-1');

    Livewire::test(OpeningBalanceEditor::class, [
        'accountId' => $account->id,
        'accountName' => 'ASN',
        'accountKind' => 'bank',
        'currentOpeningMinor' => null,
        'currentAsOfDate' => null,
        'currency' => 'EUR',
    ])
        ->set('openingInput', '0,00')
        ->set('asOfInput', '2026-05-01')
        ->call('save')
        ->assertSet('saved', true);

    Bus::assertDispatchedTimes(ProjectForecastJob::class, 5);
});

it('throws OpeningBalanceDivergenceWarning carrying the precise diff values', function (): void {
    CarbonImmutable::setTestNow('2026-05-01 12:00:00');

    $account = obeAccount($this->user, 'paypal', 'paypal-7');
    $run = obeImportRun($this->user);
    obeTxn($this->user, $account, $run, -10000, '2026-04-15 09:00:00', 1);

    $action = app(SetAccountOpeningBalance::class);

    try {
        ($action)($account->id, $this->user, 100000, '2026-05-01', allowDivergence: false);
        expect(false)->toBeTrue('OpeningBalanceDivergenceWarning was not raised.');
    } catch (OpeningBalanceDivergenceWarning $w) {
        expect($w->userValueMinor)->toBe(100000);
        expect($w->sumOfTransactionsMinor)->toBe(-10000);
        expect($w->diffMinor)->toBe(110000);
    }
});

it('rejects opening balance with a future as-of date', function (): void {
    CarbonImmutable::setTestNow('2026-05-01 12:00:00');

    $account = obeAccount($this->user, 'paypal', 'paypal-8');
    $action = app(SetAccountOpeningBalance::class);

    expect(fn () => ($action)($account->id, $this->user, 0, '2030-01-01', allowDivergence: false))
        ->toThrow(InvalidArgumentException::class, 'Opening balance date cannot be in the future.');
});
