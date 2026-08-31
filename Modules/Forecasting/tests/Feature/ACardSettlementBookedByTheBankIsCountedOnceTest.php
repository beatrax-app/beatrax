<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Public\Enums\CardStatementState;
use Modules\Chains\Public\Support\StatementDueDate;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\ProjectionPipeline;
use Modules\Forecasting\Public\Dto\ForecastPointDto;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;

uses(RefreshDatabase::class);

const CSB_HORIZON_DAYS = 365;

const CSB_TODAY = '2026-08-23';

const CSB_PERIOD_END = '2026-08-31 23:59:59';

const CSB_SETTLEMENT_MINOR = 145_000;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CSB_TODAY.' 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'csb',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);

    $this->bankId = csbAccount($this->db, $this->user->id, AccountKind::Bank->value, 'CSB Bank');
    $cardId = csbAccount($this->db, $this->user->id, AccountKind::IcsCard->value, 'CSB Card');
    csbStatement($this->db, $this->user->id, $cardId, CSB_SETTLEMENT_MINOR);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function csbAccount(DatabaseManager $db, int $userId, string $kind, string $name): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'csb-'.$hex,
        'kind' => $kind,
        'iban' => 'NL00CSB'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'starting_balance_minor' => 500_000,
        'starting_balance_date' => '2026-01-01',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function csbStatement(DatabaseManager $db, int $userId, int $cardAccountId, int $openBalanceMinor): int
{
    return $db->connection()->table('card_statements')->insertGetId([
        'user_id' => $userId,
        'account_id' => $cardAccountId,
        'import_run_id' => null,
        'period_start' => '2026-08-01 00:00:00',
        'period_end' => CSB_PERIOD_END,
        'total_amount_minor' => -$openBalanceMinor,
        'open_balance_minor' => $openBalanceMinor,
        'currency' => Currency::Eur->value,
        'state' => CardStatementState::Open->value,
        'created_at' => '2026-08-01 00:00:00',
        'updated_at' => '2026-08-01 00:00:00',
    ]);
}

function csbTransaction(
    DatabaseManager $db,
    int $userId,
    int $accountId,
    string $postedAt,
    int $settledMinor,
    string $counterpartyNormalized = 'ics-cards',
): int {
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/csb-'.$hex.'.csv',
        'sha256' => hash('sha256', 'csb-'.$hex),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'imported',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'csb-fp-'.$hex),
        'fingerprint_version' => 3,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $settledMinor,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => $counterpartyNormalized,
        'counterparty_name' => 'International Card Services',
        'normalization_version' => 1,
        'description' => 'csb fixture',
        'type' => TransactionType::Expense->value,
        'source_format' => 'asn-csv',
        'source_row_index' => $row,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function csbDueDate(): string
{
    return StatementDueDate::of(null, CSB_PERIOD_END)->toDateString();
}

/**
 * @param  list<ForecastPointDto>  $points
 */
function csbPointOn(array $points, string $date): int
{
    foreach ($points as $point) {
        if ($point->date === $date) {
            return $point->pointMinor;
        }
    }

    throw new RuntimeException('No forecast point on '.$date);
}

/**
 * @return list<ForecastPointDto>
 */
function csbCurve(User $user, int $bankId): array
{
    app(ProjectionPipeline::class)->project($user, null, CSB_HORIZON_DAYS);

    return app(ForecastQuery::class)->forUser($bankId, CSB_HORIZON_DAYS, null, $user)->points;
}

/**
 * @param  list<ForecastPointDto>  $points
 * @return int everything the curve gives up between the eve of the due date and $through
 */
function csbFallThrough(array $points, string $through): int
{
    $eve = CarbonImmutable::parse(csbDueDate())->subDay()->toDateString();

    return csbPointOn($points, $through) - csbPointOn($points, $eve);
}

// The reader's bank statement already carries the settlement as a future-dated
// direct debit, so the ledger holds the very charge the router infers from the
// open statement. Both reached the fold and the curve dipped twice.
it('counts a settlement the bank has already booked once', function (): void {
    csbTransaction($this->db, $this->user->id, $this->bankId, csbDueDate(), -CSB_SETTLEMENT_MINOR);

    $points = csbCurve($this->user, $this->bankId);

    expect(csbFallThrough($points, csbDueDate()))->toBe(-CSB_SETTLEMENT_MINOR);
});

// A charge that posted after the period closed leaves the debit a little above
// the balance the statement was written for. It is still the one payment.
it('counts it once when the booked amount moved inside the tolerance', function (): void {
    $booked = CSB_SETTLEMENT_MINOR + 1_000;
    csbTransaction($this->db, $this->user->id, $this->bankId, csbDueDate(), -$booked);

    $points = csbCurve($this->user, $this->bankId);

    expect(csbFallThrough($points, csbDueDate()))->toBe(-$booked);
});

// A bank that moves a direct debit off a weekend still settles the card once.
it('counts it once when the bank booked it a few days off the due date', function (): void {
    $bookedOn = CarbonImmutable::parse(csbDueDate())->addDays(3)->toDateString();
    csbTransaction($this->db, $this->user->id, $this->bankId, $bookedOn, -CSB_SETTLEMENT_MINOR);

    $points = csbCurve($this->user, $this->bankId);

    expect(csbFallThrough($points, $bookedOn))->toBe(-CSB_SETTLEMENT_MINOR);
});

// Outside the tolerance the two figures are not evidence of one payment, and
// a settlement dropped on a guess is a shortfall the reader never sees coming.
it('keeps both when a booked row on the due date is not the settlement', function (): void {
    csbTransaction($this->db, $this->user->id, $this->bankId, csbDueDate(), -20_000, 'a-different-merchant');

    $points = csbCurve($this->user, $this->bankId);

    expect(csbFallThrough($points, csbDueDate()))->toBe(-CSB_SETTLEMENT_MINOR - 20_000);
});

// The same reasoning where the two figures are close but not close enough: a
// part payment is not the settlement, and netting one off the other would
// invent a figure neither the ledger nor the statement states.
it('keeps both when the booked amount falls short of the settlement', function (): void {
    csbTransaction($this->db, $this->user->id, $this->bankId, csbDueDate(), -100_000);

    $points = csbCurve($this->user, $this->bankId);

    expect(csbFallThrough($points, csbDueDate()))->toBe(-CSB_SETTLEMENT_MINOR - 100_000);
});
