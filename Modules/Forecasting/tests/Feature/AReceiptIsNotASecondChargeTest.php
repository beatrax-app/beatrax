<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\ProjectionPipeline;
use Modules\Forecasting\Internal\Support\ForecastChartView;
use Modules\Forecasting\Public\Services\NetWorthQuery;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;

uses(RefreshDatabase::class);

// Google Play publishes receipts and no statement, so a parsed receipt lands on
// a synthetic account of its own — and the card or wallet that actually paid
// carries the same purchase as `GOOGLE*WORKSPACE`. Both fixtures in this repo
// hold that second leg: ics-sample-1.txt has the card line, paypal-sample-1.csv
// has ten `Google Payment Ireland` debits. Two rows, one purchase, and a
// roll-up that summed both accounts subtracted it twice.

const RSC_TODAY = '2026-08-23';

const RSC_CHARGE_DAY = '2026-07-10';

const RSC_BANK_OPENING_MINOR = 200_000;

const RSC_CHARGE_MINOR = 599;

const RSC_HORIZON_DAYS = 365;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(RSC_TODAY.' 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'rsc-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function rscAccount(DatabaseManager $db, int $userId, string $name, string $kind, int $openingMinor): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'rsc-'.$hex,
        'kind' => $kind,
        'iban' => 'NL00RSC'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'opening_balance_minor' => $openingMinor,
        'opening_balance_as_of_date' => '2026-04-01',
        'created_at' => '2026-04-01 00:00:00',
        'updated_at' => '2026-04-01 00:00:00',
    ]);
}

function rscRow(DatabaseManager $db, int $userId, int $accountId, int $minor, string $counterparty): void
{
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rsc-'.$hex.'.csv',
        'sha256' => hash('sha256', 'rsc-'.$hex),
        'uploaded_at' => RSC_CHARGE_DAY.' 08:00:00',
        'status' => 'imported',
        'created_at' => RSC_CHARGE_DAY.' 08:00:00',
        'updated_at' => RSC_CHARGE_DAY.' 08:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'rsc-fp-'.$hex),
        'fingerprint_version' => 3,
        'posted_at' => RSC_CHARGE_DAY,
        'booked_at' => RSC_CHARGE_DAY.' 12:00:00',
        'value_date' => RSC_CHARGE_DAY,
        'amount_minor' => $minor,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => $minor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => Str::slug($counterparty),
        'counterparty_name' => $counterparty,
        'normalization_version' => 1,
        'description' => 'rsc fixture',
        'type' => TransactionType::Expense->value,
        'source_format' => 'asn-csv',
        'source_row_index' => $row,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => RSC_CHARGE_DAY.' 12:00:00',
        'updated_at' => RSC_CHARGE_DAY.' 12:00:00',
    ]);
}

it('subtracts one Play purchase once when the card statement carries it too', function (): void {
    $userId = (int) $this->user->id;
    rscAccount($this->db, $userId, 'RSC Bank', AccountKind::Bank->value, RSC_BANK_OPENING_MINOR);
    $cardId = rscAccount($this->db, $userId, 'RSC ICS Card', AccountKind::IcsCard->value, 0);
    $playId = rscAccount($this->db, $userId, 'RSC Google Play', AccountKind::GooglePlay->value, 0);

    rscRow($this->db, $userId, $cardId, -RSC_CHARGE_MINOR, 'GOOGLE*WORKSPACE SAMEN');
    rscRow($this->db, $userId, $playId, -RSC_CHARGE_MINOR, 'Google Workspace');

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($netWorth->totalMinor)->toBe(RSC_BANK_OPENING_MINOR - RSC_CHARGE_MINOR);
});

// A tally that is only ever debited is not a debt. Refunds are skipped by the
// matcher, so the account can only run further negative for as long as the
// reader keeps buying, and counting it as a liability would walk net worth
// down for ever with nothing on the other side to walk it back.
it('does not read a Play account with no funding leg imported as money owed', function (): void {
    $userId = (int) $this->user->id;
    rscAccount($this->db, $userId, 'RSC Bank', AccountKind::Bank->value, RSC_BANK_OPENING_MINOR);
    $playId = rscAccount($this->db, $userId, 'RSC Google Play', AccountKind::GooglePlay->value, 0);

    rscRow($this->db, $userId, $playId, -RSC_CHARGE_MINOR, 'Spotify Premium');

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($netWorth->totalMinor)->toBe(RSC_BANK_OPENING_MINOR)
        ->and($netWorth->accounts)->toHaveCount(1);
});

// The /forecast "All accounts" tab is net worth drawn over time, and the tab
// list beside it is every account the reader has. Summing the tab list was
// summing the mirrors too, so the curve opened above the card it was meant to
// agree with — the same arithmetic, one surface further on.
it('opens the all-accounts curve on the figure the dashboard rolls up', function (): void {
    $userId = (int) $this->user->id;
    rscAccount($this->db, $userId, 'RSC Bank', AccountKind::Bank->value, RSC_BANK_OPENING_MINOR);
    $cardId = rscAccount($this->db, $userId, 'RSC ICS Card', AccountKind::IcsCard->value, 0);
    $playId = rscAccount($this->db, $userId, 'RSC Google Play', AccountKind::GooglePlay->value, 0);

    rscRow($this->db, $userId, $cardId, -RSC_CHARGE_MINOR, 'GOOGLE*WORKSPACE SAMEN');
    rscRow($this->db, $userId, $playId, -RSC_CHARGE_MINOR, 'Google Workspace');

    app(ProjectionPipeline::class)->project($this->user, null, RSC_HORIZON_DAYS);

    $charts = app(ForecastChartView::class);
    $accountList = $charts->accountList($this->user);
    $aggregate = $charts->aggregate($accountList, RSC_HORIZON_DAYS, $this->user, Currency::Eur->value);

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    /** @var list<array{date: string, point_minor: int}> $points */
    $points = $aggregate['aggregatePoints'];

    expect($accountList)->toHaveCount(3)
        ->and($points[0]['date'])->toBe(RSC_TODAY)
        ->and($points[0]['point_minor'])->toBe($netWorth->totalMinor);
});
