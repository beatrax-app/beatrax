<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Public\Http\Livewire\OpeningBalanceEditor;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Services\AccountStartingBalanceQuery;

uses(RefreshDatabase::class);

// The override outranks the import-detected baseline everywhere a balance is
// read, and the editor had no way to express its absence: blanking the box was
// rejected as "not a valid number" and the stored figure survived.

function obtUser(): User
{
    return User::query()->create([
        'username' => 'obt-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function obtAccount(User $user, ?int $detectedMinor = null, ?string $detectedDate = null): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'obt account',
        'slug' => 'obt-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'OBT'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
        'starting_balance_minor' => $detectedMinor,
        'starting_balance_date' => $detectedDate,
    ]);
}

function obtStoreOverride(Account $account, int $minor, string $asOfDate): void
{
    DB::table('accounts')->where('id', $account->id)->update([
        'opening_balance_minor' => $minor,
        'opening_balance_as_of_date' => $asOfDate,
    ]);
}

/** @return object{opening_balance_minor: mixed, opening_balance_as_of_date: mixed} */
function obtStoredOverride(Account $account): object
{
    /** @var object{opening_balance_minor: mixed, opening_balance_as_of_date: mixed} $row */
    $row = DB::table('accounts')
        ->where('id', $account->id)
        ->first(['opening_balance_minor', 'opening_balance_as_of_date']);

    return $row;
}

function obtEditor(Account $account, ?int $currentMinor, ?string $currentAsOf): Testable
{
    return Livewire::test(OpeningBalanceEditor::class, [
        'accountId' => $account->id,
        'accountName' => 'obt account',
        'accountKind' => 'bank',
        'currentOpeningMinor' => $currentMinor,
        'currentAsOfDate' => $currentAsOf,
        'currency' => 'EUR',
    ]);
}

beforeEach(function (): void {
    Bus::fake();
    CarbonImmutable::setTestNow('2026-05-01 12:00:00');
    $this->user = obtUser();
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('takes the override back when the amount is blanked and saved', function (): void {
    $account = obtAccount($this->user);
    obtStoreOverride($account, 99900, '2026-04-01');

    obtEditor($account, 99900, '2026-04-01')
        ->set('openingInput', '')
        ->set('asOfInput', '')
        ->call('save')
        ->assertSet('errorMessage', null)
        ->assertSet('currentOpeningMinor', null)
        ->assertSet('currentAsOfDate', null);

    $row = obtStoredOverride($account);
    expect($row->opening_balance_minor)->toBeNull()
        ->and($row->opening_balance_as_of_date)->toBeNull();
});

it('takes the override back through the remove affordance', function (): void {
    $account = obtAccount($this->user);
    obtStoreOverride($account, 99900, '2026-04-01');

    obtEditor($account, 99900, '2026-04-01')
        ->call('remove')
        ->assertSet('openingInput', '')
        ->assertSet('asOfInput', '')
        ->assertSet('currentOpeningMinor', null)
        ->assertSet('errorMessage', null);

    expect(obtStoredOverride($account)->opening_balance_minor)->toBeNull();
});

it('offers the remove affordance only while an override is stored', function (): void {
    $account = obtAccount($this->user);
    obtStoreOverride($account, 99900, '2026-04-01');

    obtEditor($account, 99900, '2026-04-01')
        ->assertSee(Lang::get('forecasting::opening_balance.remove'));

    obtEditor(obtAccount($this->user), null, null)
        ->assertDontSee(Lang::get('forecasting::opening_balance.remove'));
});

it('falls back to the import-detected baseline once the override is taken back', function (): void {
    $account = obtAccount($this->user, detectedMinor: 5000, detectedDate: '2026-03-01');
    obtStoreOverride($account, 99900, '2026-04-01');

    $query = app(AccountStartingBalanceQuery::class);

    expect($query->forAccount($account->id, $this->user)['minorUnits'])->toBe(99900);

    obtEditor($account, 99900, '2026-04-01')->call('remove');

    $baseline = $query->forAccount($account->id, $this->user);
    expect($baseline['minorUnits'])->toBe(5000)
        ->and($baseline['date']?->toDateString())->toBe('2026-03-01');
});

it('stores an override of exactly zero as zero rather than as none', function (): void {
    $account = obtAccount($this->user, detectedMinor: 5000, detectedDate: '2026-03-01');

    obtEditor($account, null, null)
        ->set('openingInput', '0,00')
        ->set('asOfInput', '2026-04-01')
        ->call('save')
        ->assertSet('errorMessage', null)
        ->assertSet('currentOpeningMinor', 0);

    $row = obtStoredOverride($account);
    expect((int) $row->opening_balance_minor)->toBe(0)
        ->and($row->opening_balance_minor)->not->toBeNull()
        ->and($row->opening_balance_as_of_date)->toBe('2026-04-01');

    // Zero outranks the detected 5000; removed would have fallen back to it.
    $zeroed = app(AccountStartingBalanceQuery::class)->forAccount($account->id, $this->user);
    expect($zeroed['minorUnits'])->toBe(0)
        ->and($zeroed['date']?->toDateString())->toBe('2026-04-01');
});

it('still refuses an opening balance that is not a number', function (): void {
    $account = obtAccount($this->user);

    obtEditor($account, null, null)
        ->set('openingInput', 'later')
        ->set('asOfInput', '2026-04-01')
        ->call('save')
        ->assertSet('errorMessage', Lang::get('forecasting::opening_balance.errors.invalid_number'))
        ->assertSet('saved', false);
});
