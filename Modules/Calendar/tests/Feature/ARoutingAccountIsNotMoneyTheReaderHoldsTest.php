<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Calendar\Internal\Dto\CalendarDayDto;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Services\NetWorthQuery;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;

uses(RefreshDatabase::class);

// A `paypal_funding` account names a movement that is already posted on both
// real accounts it sits between, so its rows are a second copy of money the
// ledger has counted once. Three surfaces decided that independently and one
// decided it the other way: the calendar's balance line summed the kind while
// net worth and the reports series left it out. On this fixture that is the
// EUR554.08 of funding from the ASN/PayPal regression pair -- money the reader
// moved out of their bank, which the calendar handed back to them.

const RANM_TODAY = '2026-08-23';

const RANM_YESTERDAY = '2026-08-22';

const RANM_MOVE_DAY = '2026-07-10';

const RANM_BASELINE_DAY = '2026-04-01';

const RANM_BANK_OPENING_MINOR = 200_000;

const RANM_FUNDING_MINOR = 55_408;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(RANM_TODAY.' 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'ranm-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function ranmAccount(DatabaseManager $db, int $userId, string $name, string $kind, int $startingMinor): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'ranm-'.$hex,
        'kind' => $kind,
        'iban' => 'NL00RANM'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'starting_balance_minor' => $startingMinor,
        'starting_balance_date' => RANM_BASELINE_DAY,
        'created_at' => RANM_BASELINE_DAY.' 00:00:00',
        'updated_at' => RANM_BASELINE_DAY.' 00:00:00',
    ]);
}

function ranmRow(DatabaseManager $db, int $userId, int $accountId, int $minor, string $type, string $counterparty): void
{
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ranm-'.$hex.'.csv',
        'sha256' => hash('sha256', 'ranm-'.$hex),
        'uploaded_at' => RANM_MOVE_DAY.' 08:00:00',
        'status' => 'imported',
        'created_at' => RANM_MOVE_DAY.' 08:00:00',
        'updated_at' => RANM_MOVE_DAY.' 08:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'ranm-fp-'.$hex),
        'fingerprint_version' => 3,
        'posted_at' => RANM_MOVE_DAY,
        'booked_at' => RANM_MOVE_DAY.' 12:00:00',
        'value_date' => RANM_MOVE_DAY,
        'amount_minor' => $minor,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => $minor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => Str::slug($counterparty),
        'counterparty_name' => $counterparty,
        'normalization_version' => 1,
        'description' => 'ranm fixture',
        'type' => $type,
        'source_format' => 'asn-csv',
        'source_row_index' => $row,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => RANM_MOVE_DAY.' 12:00:00',
        'updated_at' => RANM_MOVE_DAY.' 12:00:00',
    ]);
}

// The bank funds the wallet, the wallet pays the merchant, and a routing
// account carries the funding leg a third time. Only the bank is out of pocket.
function ranmSeedFundedPurchase(DatabaseManager $db, int $userId): void
{
    $bankId = ranmAccount($db, $userId, 'RANM Bank', AccountKind::Bank->value, RANM_BANK_OPENING_MINOR);
    $walletId = ranmAccount($db, $userId, 'RANM PayPal Wallet', AccountKind::Paypal->value, 0);
    $routingId = ranmAccount($db, $userId, 'RANM PayPal Funding', AccountKind::PaypalFunding->value, 0);

    ranmRow($db, $userId, $bankId, -RANM_FUNDING_MINOR, TransactionType::TransferOut->value, 'PAYPAL EUROPE SARL');
    ranmRow($db, $userId, $walletId, RANM_FUNDING_MINOR, TransactionType::TransferIn->value, 'PAYPAL EUROPE SARL');
    ranmRow($db, $userId, $walletId, -RANM_FUNDING_MINOR, TransactionType::Expense->value, 'ADOBE SYSTEMS SOFTWARE');
    ranmRow($db, $userId, $routingId, RANM_FUNDING_MINOR, TransactionType::TransferIn->value, 'PAYPAL EUROPE SARL');
}

it('does not hand back on the calendar the funding the bank actually paid out', function (): void {
    ranmSeedFundedPurchase($this->db, (int) $this->user->id);

    $days = app(CalendarQuery::class)->forMonth($this->user, 2026, 8, null, null);

    $yesterday = null;
    foreach ($days as $day) {
        /** @var CalendarDayDto $day */
        if ($day->date->toDateString() === RANM_YESTERDAY) {
            $yesterday = $day;
        }
    }

    $netWorth = app(NetWorthQuery::class)->forUser($this->user);

    expect($yesterday)->not->toBeNull()
        ->and($yesterday->hasBalanceFigure)->toBeTrue()
        ->and($netWorth->totalMinor)->toBe(RANM_BANK_OPENING_MINOR - RANM_FUNDING_MINOR)
        ->and($yesterday->eodBalanceMinor)->toBe($netWorth->totalMinor);
});
