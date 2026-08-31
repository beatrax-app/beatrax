<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Actions\SetAccountOpeningBalance;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\AccountBalanceQuery;

uses(RefreshDatabase::class);

// "Use Beatrax's number" wrote the sum of native amount_minor across every
// currency the account holds, bounded on booked_at, with the account's own
// baseline left out. One click took ASN Bank from EUR6,604.64 to EUR3,612.14 —
// across net worth, reconcile, pots, the calendar and the forecast at once.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'obsug',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function obsugAccount(DatabaseManager $db, int $userId, array $overrides = []): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId(array_merge([
        'user_id' => $userId,
        'name' => 'ASN Betaalrekening',
        'slug' => 'obsug-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00OBSUG'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ], $overrides));
}

function obsugRow(
    DatabaseManager $db,
    int $userId,
    int $accountId,
    string $postedAt,
    int $settledMinor,
    string $settledCurrency = Currency::Eur->value,
    ?int $nativeMinor = null,
    ?string $bookedAt = null,
): void {
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/obsug-'.$hex.'.csv',
        'sha256' => hash('sha256', 'obsug-'.$hex),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'imported',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'obsug-fp-'.$hex),
        'fingerprint_version' => 3,
        'posted_at' => $postedAt,
        'booked_at' => $bookedAt ?? $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $nativeMinor ?? $settledMinor,
        'currency' => $settledCurrency,
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => $settledCurrency,
        'counterparty_normalized' => 'obsug',
        'counterparty_name' => 'OBSUG',
        'normalization_version' => 1,
        'description' => 'obsug fixture',
        'type' => $settledMinor >= 0 ? TransactionType::Income->value : TransactionType::Expense->value,
        'source_format' => 'asn-csv',
        'source_row_index' => $row,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function obsugToday(): CarbonImmutable
{
    return CarbonImmutable::now()->startOfDay();
}

it('offers the figure every other balance surface already agrees on', function (): void {
    $accountId = obsugAccount($this->db, $this->user->id, [
        'starting_balance_minor' => 600_000,
        'starting_balance_date' => '2026-01-01',
    ]);

    obsugRow($this->db, $this->user->id, $accountId, '2026-06-01', 80_464);
    obsugRow($this->db, $this->user->id, $accountId, '2026-08-20', -20_000);

    $suggested = app(SetAccountOpeningBalance::class)->positionOn($accountId, $this->user, obsugToday());
    $ledger = app(AccountBalanceQuery::class)
        ->currentBalanceAsOf($accountId, $this->user, obsugToday())
        ->in(Currency::Eur->value);

    expect($suggested)->toBe(660_464)
        ->and($suggested)->toBe($ledger);
});

// The shipped shape: a euro account holding one dollar-settled line. Summing
// native amount_minor added dollar cents to euro cents.
it('sums the account\'s own denomination rather than native amounts across currencies', function (): void {
    $accountId = obsugAccount($this->db, $this->user->id, [
        'starting_balance_minor' => 600_000,
        'starting_balance_date' => '2026-01-01',
    ]);

    obsugRow($this->db, $this->user->id, $accountId, '2026-06-01', 60_464);
    obsugRow(
        $this->db,
        $this->user->id,
        $accountId,
        '2026-08-01',
        -120_000,
        Currency::Usd->value,
        nativeMinor: -120_000,
    );

    $suggested = app(SetAccountOpeningBalance::class)->positionOn($accountId, $this->user, obsugToday());

    expect($suggested)->toBe(660_464);
});

// A row booked on the evening of the as-of day but posted the next morning is
// not on the account yet; bounding on booked_at counted it.
it('bounds on posted_at, the column every other balance sum bounds on', function (): void {
    $accountId = obsugAccount($this->db, $this->user->id, [
        'starting_balance_minor' => 100_000,
        'starting_balance_date' => '2026-01-01',
    ]);

    obsugRow($this->db, $this->user->id, $accountId, '2026-08-24', -50_000, bookedAt: '2026-08-23 23:30:00');

    $suggested = app(SetAccountOpeningBalance::class)->positionOn($accountId, $this->user, obsugToday());
    $ledger = app(AccountBalanceQuery::class)
        ->currentBalanceAsOf($accountId, $this->user, obsugToday())
        ->in(Currency::Eur->value);

    expect($suggested)->toBe(100_000)
        ->and($suggested)->toBe($ledger);
});

// The derivation has to be independent of the figure it is checking, or the
// warning agrees with whatever was last saved however wrong it was.
it('derives its number without reading back the override it is validating', function (): void {
    $accountId = obsugAccount($this->db, $this->user->id, [
        'starting_balance_minor' => 600_000,
        'starting_balance_date' => '2026-01-01',
        'opening_balance_minor' => 1,
        'opening_balance_as_of_date' => '2026-08-01',
    ]);

    obsugRow($this->db, $this->user->id, $accountId, '2026-06-01', 60_464);

    $suggested = app(SetAccountOpeningBalance::class)->positionOn($accountId, $this->user, obsugToday());

    expect($suggested)->toBe(660_464);
});

// The column is denominated in the account's own currency. With none named
// there is no figure to offer, so none is offered and no warning is raised.
it('withholds the suggestion for an account that names no currency', function (): void {
    $accountId = obsugAccount($this->db, $this->user->id, ['default_currency' => '']);
    obsugRow($this->db, $this->user->id, $accountId, '2026-06-01', 60_464);

    expect(app(SetAccountOpeningBalance::class)->positionOn($accountId, $this->user, obsugToday()))->toBeNull();

    $save = fn (): mixed => app(SetAccountOpeningBalance::class)(
        $accountId,
        $this->user,
        999_999,
        '2026-08-23',
    );

    expect($save)->not->toThrow(Exception::class);
});
