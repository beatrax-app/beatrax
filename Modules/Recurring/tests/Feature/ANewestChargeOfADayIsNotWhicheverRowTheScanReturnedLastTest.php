<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Recurring\Internal\Detectors\ExpenseSeriesDetector;
use Modules\Recurring\Internal\Detectors\IncomeSeriesDetector;
use Modules\Recurring\Models\RecurringSeries;

// posted_at is a DATE, so two charges from one merchant on one day tie under
// `ORDER BY posted_at`. The detector reads the LAST row of the cluster as the
// series' latest amount, so with no second sort term the figure the series
// reports is whichever row the scan happened to return last.

function ntbUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'recurring_detection_window_months' => 36,
        'recurring_income_min_amount_minor' => 0,
    ]);
}

function ntbAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ntb asn',
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL00NTB0000000001',
        'default_currency' => Currency::Eur->value,
    ]);
}

function ntbRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ntb.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);
}

function ntbTx(
    DatabaseManager $db,
    User $user,
    Account $account,
    ImportRun $run,
    string $postedAt,
    int $amountMinor,
    string $counterparty,
    TransactionType $type,
    string $seed,
    ?string $iban = null,
): void {
    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type->value,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_name' => ucfirst($counterparty),
        'counterparty_iban' => $iban,
        'counterparty_normalized' => $counterparty,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => crc32($seed) % 100000,
        'fingerprint' => str_pad($seed, 64, 'a', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
}

// The same two same-day charges, written in the two orders two devices can
// write them in. `$giftCardFirst` is the only difference between the runs.
function ntbLatestExpenseMinor(bool $giftCardFirst): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = $giftCardFirst ? 'a' : 'b';
    $user = ntbUser('ntb-expense-'.$suffix);
    $account = ntbAccount($user, 'ntb-expense-'.$suffix);
    $run = ntbRun($user, str_pad($suffix, 64, '0'));

    foreach (['2026-03-04', '2026-04-04'] as $i => $postedAt) {
        ntbTx($db, $user, $account, $run, $postedAt, -1099, 'netflix', TransactionType::Expense, 'ntb-e-'.$suffix.$i);
    }

    // Both on the subscription's own billing day, and both inside the ±25%
    // band around the cluster median, so the filter keeps them both.
    $sameDay = [
        ['seed' => 'ntb-e-gift-'.$suffix, 'minor' => -1299],
        ['seed' => 'ntb-e-sub-'.$suffix, 'minor' => -1099],
    ];
    if (! $giftCardFirst) {
        $sameDay = array_reverse($sameDay);
    }
    foreach ($sameDay as $charge) {
        ntbTx($db, $user, $account, $run, '2026-05-04', $charge['minor'], 'netflix', TransactionType::Expense, $charge['seed']);
    }

    app(ExpenseSeriesDetector::class)->detectForUser($user);

    /** @var RecurringSeries $series */
    $series = RecurringSeries::query()
        ->where('user_id', $user->id)
        ->where('direction', Direction::Expense->value)
        ->firstOrFail();

    return $series->latest_amount_minor;
}

function ntbLatestIncomeMinor(bool $bonusFirst): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = $bonusFirst ? 'a' : 'b';
    $user = ntbUser('ntb-income-'.$suffix);
    $account = ntbAccount($user, 'ntb-income-'.$suffix);
    $run = ntbRun($user, str_pad($suffix.'i', 64, '0'));
    $iban = 'NL56PAYR0000000009';

    foreach (['2026-03-25', '2026-04-25'] as $i => $postedAt) {
        ntbTx($db, $user, $account, $run, $postedAt, 350000, 'acme payroll', TransactionType::Income, 'ntb-i-'.$suffix.$i, $iban);
    }

    $sameDay = [
        ['seed' => 'ntb-i-bonus-'.$suffix, 'minor' => 420000],
        ['seed' => 'ntb-i-sal-'.$suffix, 'minor' => 350000],
    ];
    if (! $bonusFirst) {
        $sameDay = array_reverse($sameDay);
    }
    foreach ($sameDay as $payment) {
        ntbTx($db, $user, $account, $run, '2026-05-25', $payment['minor'], 'acme payroll', TransactionType::Income, $payment['seed'], $iban);
    }

    app(IncomeSeriesDetector::class)->detectForUser($user);

    /** @var RecurringSeries $series */
    $series = RecurringSeries::query()
        ->where('user_id', $user->id)
        ->where('direction', Direction::Income->value)
        ->firstOrFail();

    return $series->latest_amount_minor;
}

// The same two charges, written in the same order, with the two fingerprints
// two paired devices give them. FingerprintComposer folds user_id and
// account_id, both counted per device, so one IBAN is account 1 here and
// account 2 there and the digests land in either order.
function ntbLatestExpenseMinorByDigest(bool $subscriptionSortsLast): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = $subscriptionSortsLast ? 'c' : 'd';
    $user = ntbUser('ntb-digest-'.$suffix);
    $account = ntbAccount($user, 'ntb-digest-'.$suffix);
    $run = ntbRun($user, str_pad($suffix.'d', 64, '0'));

    foreach (['2026-03-04', '2026-04-04'] as $i => $postedAt) {
        ntbTx($db, $user, $account, $run, $postedAt, -1099, 'netflix', TransactionType::Expense, 'ntb-d-'.$suffix.$i);
    }

    // str_pad LEFT-pads with 'a', so a seed starting 'z' sorts last and a seed
    // starting 'a' sorts first — the whole difference between the two runs.
    $subscriptionSeed = $subscriptionSortsLast ? 'zsub-'.$suffix : 'asub-'.$suffix;
    $giftCardSeed = $subscriptionSortsLast ? 'agift-'.$suffix : 'zgift-'.$suffix;

    ntbTx($db, $user, $account, $run, '2026-05-04', -1299, 'netflix', TransactionType::Expense, $giftCardSeed);
    ntbTx($db, $user, $account, $run, '2026-05-04', -1099, 'netflix', TransactionType::Expense, $subscriptionSeed);

    app(ExpenseSeriesDetector::class)->detectForUser($user);

    /** @var RecurringSeries $series */
    $series = RecurringSeries::query()
        ->where('user_id', $user->id)
        ->where('direction', Direction::Expense->value)
        ->firstOrFail();

    return $series->latest_amount_minor;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-27 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('reports the same expense amount whichever order the day\'s two charges were written in', function (): void {
    $written = ntbLatestExpenseMinor(true);

    // Both charges named, so a run that stopped reading the cluster at all
    // could not pass this by answering the same nothing twice.
    expect($written)->toBe(ntbLatestExpenseMinor(false))
        ->and([-1099, -1299])->toContain($written);
});

it('reports the same income amount whichever order the day\'s two payments were written in', function (): void {
    $written = ntbLatestIncomeMinor(true);

    expect($written)->toBe(ntbLatestIncomeMinor(false))
        ->and([350000, 420000])->toContain($written);
});

it('reports the same expense amount whichever order the two devices digests fall in', function (): void {
    $subscriptionLast = ntbLatestExpenseMinorByDigest(true);

    expect($subscriptionLast)->toBe(ntbLatestExpenseMinorByDigest(false))
        ->and([-1099, -1299])->toContain($subscriptionLast);
});
