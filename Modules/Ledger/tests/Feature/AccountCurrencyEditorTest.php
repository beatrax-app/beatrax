<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Locale;
use Modules\Ledger\Internal\Actions\SetAccountCurrency;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Http\Livewire\AccountCurrencyEditor;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Ledger\Public\Services\BaseCurrency;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

function accCurUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function accCurAccount(User $user, string $slug, string $currency = 'EUR', ?int $startingMinor = 0): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'acc-cur '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'ACCCUR-'.strtoupper($slug),
        'default_currency' => $currency,
        'starting_balance_minor' => $startingMinor,
    ]);
}

function accCurRun(User $user): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/acc-cur.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => '2026-08-01 00:00:00',
        'status' => 'previewed',
    ]);
}

function accCurTxn(User $user, Account $account, ImportRun $run, int $settledMinor, string $settledCurrency, int $row): Transaction
{
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $settledMinor < 0 ? 'expense' : 'income',
        'posted_at' => '2026-08-01',
        'booked_at' => '2026-08-01 00:00:00',
        'value_date' => '2026-08-01',
        'amount_minor' => $settledMinor,
        'currency' => $settledCurrency,
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => $settledCurrency,
        'counterparty_name' => 'acc-cur txn',
        'counterparty_normalized' => 'acc-cur txn',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $row,
        'fingerprint' => str_pad('acc-cur-'.$row, 64, 'c', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

/**
 * @return Testable
 */
function accCurEditor(Account $account): object
{
    return Livewire::test(AccountCurrencyEditor::class, [
        'accountId' => $account->id,
        'accountName' => $account->name,
        'currency' => $account->default_currency,
    ]);
}

it('persists a chosen currency and reads it back on the next mount', function (): void {
    $user = accCurUser('acc-cur-persist');
    $this->actingAs($user);
    $account = accCurAccount($user, 'persist');

    accCurEditor($account)
        ->set('currency', Currency::Usd->value)
        ->call('save')
        ->assertSet('storedCurrency', Currency::Usd->value)
        ->assertSet('showingRelabelBanner', false)
        ->assertDispatched('toast');

    expect(Account::query()->findOrFail($account->id)->default_currency)->toBe(Currency::Usd->value);

    accCurEditor(Account::query()->findOrFail($account->id))
        ->assertSet('currency', Currency::Usd->value)
        ->assertSet('storedCurrency', Currency::Usd->value);
});

it('answers the balance in the new currency and keeps the old currency as its own line', function (): void {
    $user = accCurUser('acc-cur-lines');
    $this->actingAs($user);
    // A non-zero baseline is what makes the relabel observable: the baseline
    // opens the line of whatever the account is denominated in, so it is the
    // figure that moves from one line to the other while the rows stay put.
    $account = accCurAccount($user, 'lines', startingMinor: 20000);
    $run = accCurRun($user);

    accCurTxn($user, $account, $run, 5000, Currency::Usd->value, 1);
    accCurTxn($user, $account, $run, 2500, Currency::Usd->value, 2);
    accCurTxn($user, $account, $run, 1000, Currency::Eur->value, 3);

    $before = app(AccountBalanceQuery::class)->currentBalance($account->id, $user);
    expect($before->lines())->toBe([Currency::Eur->value => 21000, Currency::Usd->value => 7500]);

    accCurEditor($account)
        ->set('currency', Currency::Usd->value)
        ->call('save')
        ->assertSet('showingRelabelBanner', true)
        ->call('relabelAnyway')
        ->assertSet('showingRelabelBanner', false)
        ->assertSet('storedCurrency', Currency::Usd->value);

    $after = app(AccountBalanceQuery::class)->currentBalance($account->id, $user->fresh());

    expect($after->in(Currency::Usd->value))->toBe(27500)
        ->and($after->in(Currency::Eur->value))->toBe(1000)
        ->and($after->lines())->toBe([Currency::Eur->value => 1000, Currency::Usd->value => 27500]);
});

it('reports zero for a currency the account holds no rows in rather than a converted figure', function (): void {
    $user = accCurUser('acc-cur-zero');
    $this->actingAs($user);
    $account = accCurAccount($user, 'zero', startingMinor: null);
    $run = accCurRun($user);

    accCurTxn($user, $account, $run, 4200, Currency::Eur->value, 1);

    accCurEditor($account)
        ->set('currency', Currency::Gbp->value)
        ->call('save')
        ->assertSet('showingRelabelBanner', true)
        ->call('relabelAnyway');

    $after = app(AccountBalanceQuery::class)->currentBalance($account->id, $user->fresh());

    expect($after->in(Currency::Gbp->value))->toBe(0)
        ->and($after->in(Currency::Eur->value))->toBe(4200)
        ->and($after->lines())->toBe([Currency::Eur->value => 4200, Currency::Gbp->value => 0]);
});

it('leaves every stored settled amount and currency untouched', function (): void {
    $user = accCurUser('acc-cur-stored');
    $this->actingAs($user);
    $account = accCurAccount($user, 'stored');
    $run = accCurRun($user);

    accCurTxn($user, $account, $run, -1234, Currency::Eur->value, 1);
    accCurTxn($user, $account, $run, 9876, Currency::Usd->value, 2);

    $before = Transaction::query()->orderBy('id')->get(['settled_amount_minor', 'settled_currency', 'amount_minor', 'currency'])->toArray();

    accCurEditor($account)
        ->set('currency', Currency::Usd->value)
        ->call('save')
        ->call('relabelAnyway');

    $after = Transaction::query()->orderBy('id')->get(['settled_amount_minor', 'settled_currency', 'amount_minor', 'currency'])->toArray();

    expect($after)->toBe($before);
});

it('still gives a brand-new account the base currency', function (): void {
    $user = accCurUser('acc-cur-default');
    $this->actingAs($user);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'acc-cur fresh',
        'slug' => 'fresh',
        'kind' => 'bank',
        'iban' => 'ACCCUR-FRESH',
    ]);

    expect($account->refresh()->default_currency)->toBe(app(BaseCurrency::class)->code());

    accCurEditor($account)->assertSet('currency', app(BaseCurrency::class)->code());
});

it('changes an untouched account without a warning, and warns before relabelling one that holds something', function (): void {
    $user = accCurUser('acc-cur-warn');
    $this->actingAs($user);

    $empty = accCurAccount($user, 'empty', startingMinor: null);
    accCurEditor($empty)
        ->set('currency', Currency::Gbp->value)
        ->call('save')
        ->assertSet('showingRelabelBanner', false)
        ->assertSet('storedCurrency', Currency::Gbp->value);

    $held = accCurAccount($user, 'held', startingMinor: 15000);
    accCurEditor($held)
        ->set('currency', Currency::Gbp->value)
        ->call('save')
        ->assertSet('showingRelabelBanner', true)
        ->assertSet('relabelBaselineMinor', 15000)
        ->assertSeeHtml('account-currency-relabel-banner');

    expect(Account::query()->findOrFail($held->id)->default_currency)->toBe(Currency::Eur->value);
});

it('puts the select back and writes nothing when the warning is declined', function (): void {
    $user = accCurUser('acc-cur-keep');
    $this->actingAs($user);
    $account = accCurAccount($user, 'keep', startingMinor: 15000);

    accCurEditor($account)
        ->set('currency', Currency::Gbp->value)
        ->call('save')
        ->assertSet('showingRelabelBanner', true)
        ->call('keepCurrent')
        ->assertSet('currency', Currency::Eur->value)
        ->assertSet('showingRelabelBanner', false);

    expect(Account::query()->findOrFail($account->id)->default_currency)->toBe(Currency::Eur->value);
});

it('refuses a currency code the install does not carry', function (): void {
    $user = accCurUser('acc-cur-unknown');
    $this->actingAs($user);
    $account = accCurAccount($user, 'unknown');

    accCurEditor($account)
        ->set('currency', 'ZZZ')
        ->call('save')
        ->assertSet('currency', Currency::Eur->value)
        ->assertSet('errorMessage', 'That is not a currency this install knows.');

    expect(Account::query()->findOrFail($account->id)->default_currency)->toBe(Currency::Eur->value);
});

it('refuses a cross-user account at the Action layer', function (): void {
    // The Livewire harness does not propagate a mount-time
    // NotFoundHttpException through ->toThrow(), so the Action carries the
    // assertion instead; the component still raises it over real HTTP.
    $owner = accCurUser('acc-cur-owner');
    $intruder = accCurUser('acc-cur-intruder');
    $account = accCurAccount($owner, 'owned');

    $action = app(SetAccountCurrency::class);

    expect(fn () => ($action)($account->id, $intruder, Currency::Usd->value, allowRelabel: true))
        ->toThrow(NotFoundHttpException::class);

    expect(Account::query()->findOrFail($account->id)->default_currency)->toBe(Currency::Eur->value);
});

it('labels the select and offers the seeded currencies', function (): void {
    $user = accCurUser('acc-cur-label');
    $this->actingAs($user);
    $account = accCurAccount($user, 'label');

    accCurEditor($account)
        ->assertSeeHtml('for="account-currency-'.$account->id.'"')
        ->assertSeeHtml('id="account-currency-'.$account->id.'"')
        ->assertSeeHtml('Currency for acc-cur label')
        ->assertSeeHtml('<option value="EUR">EUR — Euro</option>')
        ->assertSeeHtml('<option value="USD">USD — US Dollar</option>')
        ->assertSee('The denomination this account reports its balance in.');
});

it('ships the editor copy in every supported locale', function (): void {
    // dirname over __DIR__ rather than base_path(): the mobile-app composer
    // root resolves base_path() to its own directory, and its Modules/ is a
    // symlink onto this same tree.
    $langRoot = dirname(__DIR__, 2).'/Resources/lang';
    $english = require $langRoot.'/en/account_currency.php';

    foreach (Locale::cases() as $locale) {
        $path = $langRoot.'/'.$locale->value.'/account_currency.php';
        expect(is_file($path))->toBeTrue($locale->value.' has no account_currency.php');
        expect(array_keys(require $path))->toBe(array_keys($english), $locale->value.' key set differs');
    }
});
