<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;

// Installs already carry the accounts the importer stamped with the reader's
// reporting currency. The rows on them were always right, so the migration
// reads those and relabels the account they disagree with.

function ratmDenominationMigration(): object
{
    return require base_path(
        'Modules/Ledger/Database/Migrations/2026_08_29_000004_relabel_an_account_the_importer_denominated_in_the_readers_currency.php'
    );
}

function ratmAccount(int $userId, string $currency, ?int $openingBalanceMinor = null): int
{
    $hex = bin2hex(random_bytes(4));

    return (int) DB::table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Account '.$hex,
        'slug' => 'account-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL57ASNB'.strtoupper($hex).'00',
        'default_currency' => $currency,
        'opening_balance_minor' => $openingBalanceMinor,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function ratmRow(int $userId, int $accountId, int $runId, string $settledCurrency): void
{
    $hex = bin2hex(random_bytes(6));

    DB::table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'ratm-'.$hex),
        'fingerprint_version' => 3,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'value_date' => '2026-05-10',
        'amount_minor' => -random_int(1, 9_999_999),
        'currency' => $settledCurrency,
        'settled_amount_minor' => -1_000,
        'settled_currency' => $settledCurrency,
        'counterparty_normalized' => 'ratm',
        'counterparty_name' => 'RATM',
        'normalization_version' => 1,
        'description' => 'fixture',
        'type' => TransactionType::Expense->value,
        'source_format' => 'asn-csv',
        'source_row_index' => random_int(1, 100_000),
        'status' => ClearedStatus::Cleared->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function ratmCurrencyOf(int $accountId): string
{
    $code = DB::table('accounts')->where('id', $accountId)->value('default_currency');

    return is_string($code) ? $code : '';
}

beforeEach(function (): void {
    $this->owner = User::query()->create([
        'username' => 'ratm-owner',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Jpy->value,
    ]);

    $this->runId = (int) DB::table('import_runs')->insertGetId([
        'user_id' => $this->owner->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ratm.csv',
        'sha256' => hash('sha256', 'ratm'),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'imported',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
});

it('relabels a yen account whose every row settled in euro', function (): void {
    $accountId = ratmAccount($this->owner->id, Currency::Jpy->value);
    foreach (range(1, 3) as $ignored) {
        ratmRow($this->owner->id, $accountId, $this->runId, Currency::Eur->value);
    }

    ratmDenominationMigration()->up();

    expect(ratmCurrencyOf($accountId))->toBe(Currency::Eur->value);
});

it('leaves an account holding two currencies alone', function (): void {
    $accountId = ratmAccount($this->owner->id, Currency::Jpy->value);
    ratmRow($this->owner->id, $accountId, $this->runId, Currency::Eur->value);
    ratmRow($this->owner->id, $accountId, $this->runId, Currency::Usd->value);

    ratmDenominationMigration()->up();

    expect(ratmCurrencyOf($accountId))->toBe(Currency::Jpy->value);
});

// The one figure a relabel would silently reinterpret is the balance the
// reader typed themselves, so that account waits for /settings, where the
// change is shown before it is made.
it('leaves an account carrying a reader-typed opening balance alone', function (): void {
    $accountId = ratmAccount($this->owner->id, Currency::Jpy->value, openingBalanceMinor: 2_158);
    ratmRow($this->owner->id, $accountId, $this->runId, Currency::Eur->value);

    ratmDenominationMigration()->up();

    expect(ratmCurrencyOf($accountId))->toBe(Currency::Jpy->value);
});

it('relabels an account whose typed opening balance is zero, which reads the same under either label', function (): void {
    $accountId = ratmAccount($this->owner->id, Currency::Jpy->value, openingBalanceMinor: 0);
    ratmRow($this->owner->id, $accountId, $this->runId, Currency::Eur->value);

    ratmDenominationMigration()->up();

    expect(ratmCurrencyOf($accountId))->toBe(Currency::Eur->value);
});

it('leaves an account with nothing on it alone', function (): void {
    $accountId = ratmAccount($this->owner->id, Currency::Jpy->value);

    ratmDenominationMigration()->up();

    expect(ratmCurrencyOf($accountId))->toBe(Currency::Jpy->value);
});

it('leaves the account its rows already agree with alone', function (): void {
    $accountId = ratmAccount($this->owner->id, Currency::Eur->value);
    ratmRow($this->owner->id, $accountId, $this->runId, Currency::Eur->value);

    ratmDenominationMigration()->up();

    expect(ratmCurrencyOf($accountId))->toBe(Currency::Eur->value);
});
