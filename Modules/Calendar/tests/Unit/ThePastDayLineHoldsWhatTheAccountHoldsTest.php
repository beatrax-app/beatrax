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
use Modules\Ledger\Public\Services\AccountBalanceQuery;

uses(RefreshDatabase::class);

// The baseline states what the account held in its OWN denomination, so it
// bounds only that line. AccountBalanceQuery says so; the calendar's past-day
// line dropped every foreign row posted before the baseline date, and the two
// surfaces then disagreed about what the same account holds on the same day.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
    $this->db = app(DatabaseManager::class);

    $this->db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();

    $this->user = User::query()->create([
        'username' => 'pdlh-baseline',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function pdlhAccount(DatabaseManager $db, int $userId, string $baselineDate, int $baselineMinor): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'PDLH Revolut',
        'slug' => 'pdlh-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'GB00PDLH'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'starting_balance_minor' => $baselineMinor,
        'starting_balance_date' => $baselineDate,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function pdlhTransaction(DatabaseManager $db, int $userId, int $accountId, string $postedAt, int $minor, string $currency): void
{
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'revolut-csv',
        'raw_file_path' => '/tmp/pdlh-'.$hex.'.csv',
        'sha256' => hash('sha256', 'pdlh-'.$hex),
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
        'counterparty_name' => 'PDLH Merchant '.$row,
        'counterparty_normalized' => 'pdlh merchant '.$row,
        'normalization_version' => 3,
        'source_format' => 'revolut-csv',
        'source_row_index' => $row,
        'fingerprint' => hash('sha256', 'pdlh-tx-'.$hex),
        'fingerprint_version' => 3,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => $postedAt.' 00:00:00',
        'updated_at' => $postedAt.' 00:00:00',
    ]);
}

it('counts a dollar row posted before the euro baseline the account balance counts', function (): void {
    $accountId = pdlhAccount($this->db, $this->user->id, '2026-06-05', 350_985);

    pdlhTransaction($this->db, $this->user->id, $accountId, '2026-06-01', -22_100, Currency::Usd->value);

    $this->db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => Currency::Usd->value,
        'rate_date' => '2026-06-11',
        'rate' => '2.0',
        'source' => 'ecb',
        'created_at' => '2026-06-11 00:00:00',
        'updated_at' => '2026-06-11 00:00:00',
    ]);

    $lines = app(AccountBalanceQuery::class)
        ->currentBalanceAsOf($accountId, $this->user, CarbonImmutable::parse('2026-06-11'))
        ->lines();

    expect($lines)->toBe([Currency::Eur->value => 350_985, Currency::Usd->value => -22_100]);

    $map = app(DailyBalanceAggregator::class)->buildBalanceMap(
        [$accountId],
        $this->user,
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    )['map'];

    expect($map['2026-06-11']->minor)->toBe(339_935);
});
