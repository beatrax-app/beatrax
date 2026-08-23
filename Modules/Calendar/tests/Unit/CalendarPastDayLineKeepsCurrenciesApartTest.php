<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Internal\Services\DailyBalanceAggregator;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;

uses(RefreshDatabase::class);

// A Revolut account holds euro and dollar rows side by side. Bucketing the
// past-day sum by the ACCOUNT's default_currency put the dollar cents in the
// euro bucket and converted nothing, so the line drew EUR3,288.85 for an
// account holding EUR3,509.85 and -USD221.00.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
    $this->db = app(DatabaseManager::class);

    // The bundled snapshot carries its own USD rate on a later date than any
    // this fixture writes, and would win the latest-rate lookup.
    $this->db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();

    $this->user = User::query()->create([
        'username' => 'cpca-mixed',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function cpcaAccount(DatabaseManager $db, int $userId): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'CPCA Revolut',
        'slug' => 'cpca-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'GB00CPCA'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function cpcaTransaction(DatabaseManager $db, int $userId, int $accountId, string $postedAt, int $minor, string $currency): void
{
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'revolut-csv',
        'raw_file_path' => '/tmp/cpca-'.$hex.'.csv',
        'sha256' => hash('sha256', 'cpca-'.$hex),
        'uploaded_at' => $postedAt.' 00:00:00',
        'status' => 'imported',
        'created_at' => $postedAt.' 00:00:00',
        'updated_at' => $postedAt.' 00:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'type' => $minor >= 0 ? 'income' : 'expense',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 00:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $minor,
        'currency' => $currency,
        'settled_amount_minor' => $minor,
        'settled_currency' => $currency,
        'counterparty_name' => 'CPCA Merchant '.$row,
        'counterparty_normalized' => 'cpca merchant '.$row,
        'normalization_version' => 3,
        'source_format' => 'revolut-csv',
        'source_row_index' => $row,
        'fingerprint' => hash('sha256', 'cpca-tx-'.$hex),
        'fingerprint_version' => 3,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => $postedAt.' 00:00:00',
        'updated_at' => $postedAt.' 00:00:00',
    ]);
}

it('converts a past day dollar rows at their own rate instead of adding their cents to the euros', function (): void {
    $accountId = cpcaAccount($this->db, $this->user->id);

    cpcaTransaction($this->db, $this->user->id, $accountId, '2026-06-10', 350_985, Currency::Eur->value);
    cpcaTransaction($this->db, $this->user->id, $accountId, '2026-06-10', -22_100, Currency::Usd->value);

    $this->db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => Currency::Usd->value,
        'rate_date' => '2026-06-11',
        'rate' => '2.0',
        'source' => 'ecb',
        'created_at' => '2026-06-11 00:00:00',
        'updated_at' => '2026-06-11 00:00:00',
    ]);

    $map = app(DailyBalanceAggregator::class)->buildBalanceMap(
        [$accountId],
        $this->user,
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    )['map'];

    expect($map['2026-06-11'][0])->toBe(339_935);
});
