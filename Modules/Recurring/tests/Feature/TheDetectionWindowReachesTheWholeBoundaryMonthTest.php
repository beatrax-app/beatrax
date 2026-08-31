<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Recurring\Internal\Detectors\ExpenseSeriesDetector;
use Modules\Recurring\Internal\Detectors\IncomeSeriesDetector;
use Modules\Recurring\Public\Support\RecurringDetectionWindow;

// subMonths() off a 29th, 30th or 31st the target month does not have rolls
// FORWARD, so on 31 August a six-month window opened on 3 March instead of
// 28 February and the first days of the boundary month never reached the
// clustering. Their anomaly siblings already use the NoOverflow variants.

const DWB_WINDOW_MONTHS = 6;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-31 12:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function dwbUser(string $username, int $windowMonths = DWB_WINDOW_MONTHS): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'recurring_detection_window_months' => $windowMonths,
    ]);
}

function dwbAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'dwb '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL00DWB'.str_pad(substr($slug, 0, 9), 11, '0', STR_PAD_RIGHT),
        'default_currency' => 'EUR',
    ]);
}

function dwbRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/dwb.csv',
        'sha256' => str_pad($sha, 64, '0'),
        'uploaded_at' => CarbonImmutable::parse('2026-08-31 00:00:00'),
        'status' => 'previewed',
    ]);
}

/**
 * Six monthly charges on the first of the month, the oldest of which sits in
 * the window's boundary month — inside a window that opens on 28 February, and
 * outside one that has slipped forward to 3 March.
 *
 * @return list<string> the posted_at dates seeded
 */
function dwbSeedMonthlySeries(DatabaseManager $db, User $user, Account $account, ImportRun $run, int $amountMinor, string $type, string $normalized): array
{
    $dates = [];
    foreach (['2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01'] as $index => $postedAt) {
        $dates[] = $postedAt;
        $db->connection()->table('transactions')->insert([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => $type,
            'posted_at' => $postedAt,
            'booked_at' => $postedAt.' 12:00:00',
            'value_date' => $postedAt,
            'amount_minor' => $amountMinor,
            'currency' => 'EUR',
            'settled_amount_minor' => $amountMinor,
            'settled_currency' => 'EUR',
            'counterparty_name' => ucfirst($normalized),
            'counterparty_normalized' => $normalized,
            'normalization_version' => 3,
            'source_format' => 'asn-csv',
            'import_run_id' => $run->id,
            'source_row_index' => $index,
            'fingerprint' => hash('sha256', $normalized.'-'.$postedAt.'-'.$user->id),
            'fingerprint_version' => 3,
            'created_at' => '2026-08-31 12:00:00',
            'updated_at' => '2026-08-31 12:00:00',
        ]);
    }

    return $dates;
}

/**
 * @return list<string>
 */
function dwbObservedDates(DatabaseManager $db, int $userId): array
{
    /** @var list<string> $dates */
    $dates = $db->connection()->table('recurring_series_occurrences')
        ->where('user_id', $userId)
        ->orderBy('observed_at')
        ->pluck('observed_at')
        ->map(static fn (mixed $value): string => substr((string) $value, 0, 10))
        ->values()
        ->all();

    return $dates;
}

it('clusters an expense charged on the first of the window boundary month', function (): void {
    $user = dwbUser('dwb-expense');
    $account = dwbAccount($user, 'dwb-expense-asn');
    $run = dwbRun($user, 'a');
    $seeded = dwbSeedMonthlySeries($this->db, $user, $account, $run, -1499, 'expense', 'dwb merchant');

    $this->app->make(ExpenseSeriesDetector::class)->detectForUser($user);

    expect(dwbObservedDates($this->db, $user->id))->toBe($seeded);
});

it('clusters an income paid on the first of the window boundary month', function (): void {
    $user = dwbUser('dwb-income');
    $account = dwbAccount($user, 'dwb-income-asn');
    $run = dwbRun($user, 'b');
    $seeded = dwbSeedMonthlySeries($this->db, $user, $account, $run, 320000, 'income', 'dwb employer');

    $this->app->make(IncomeSeriesDetector::class)->detectForUser($user);

    expect(dwbObservedDates($this->db, $user->id))->toBe($seeded);
});

it('opens the window on the same day of the boundary month whatever day it is run on', function (): void {
    $starts = [];
    foreach (['2026-08-28', '2026-08-29', '2026-08-30', '2026-08-31'] as $today) {
        CarbonImmutable::setTestNow($today.' 12:00:00');

        $user = dwbUser('dwb-run-'.$today);
        $account = dwbAccount($user, 'dwb-run-'.$today);
        $run = dwbRun($user, 'c'.substr($today, -2));
        dwbSeedMonthlySeries($this->db, $user, $account, $run, -1499, 'expense', 'dwb merchant');

        $this->app->make(ExpenseSeriesDetector::class)->detectForUser($user);

        $starts[$today] = dwbObservedDates($this->db, $user->id)[0] ?? 'nothing clustered';
    }

    expect($starts)->toBe([
        '2026-08-28' => '2026-03-01',
        '2026-08-29' => '2026-03-01',
        '2026-08-30' => '2026-03-01',
        '2026-08-31' => '2026-03-01',
    ]);
});

// The expense and income passes write into one series set, so a window either
// of them computed for itself is a set whose halves cover different months. A
// stored zero is the only input that reaches the fallback, and it used to reach
// two separately-declared ones.
it('opens one fallback window for both detectors when the stored window is zero', function (): void {
    $opensOn = CarbonImmutable::now()
        ->subMonthsNoOverflow(RecurringDetectionWindow::MINIMUM_MONTHS)
        ->toDateString();

    $expenseUser = dwbUser('dwb-zero-expense', 0);
    $seeded = dwbSeedMonthlySeries(
        $this->db,
        $expenseUser,
        dwbAccount($expenseUser, 'dwb-zeroexp-asn'),
        dwbRun($expenseUser, 'd'),
        -1499,
        'expense',
        'dwb merchant',
    );

    $incomeUser = dwbUser('dwb-zero-income', 0);
    dwbSeedMonthlySeries(
        $this->db,
        $incomeUser,
        dwbAccount($incomeUser, 'dwb-zeroinc-asn'),
        dwbRun($incomeUser, 'e'),
        320000,
        'income',
        'dwb employer',
    );

    $this->app->make(ExpenseSeriesDetector::class)->detectForUser($expenseUser);
    $this->app->make(IncomeSeriesDetector::class)->detectForUser($incomeUser);

    $inWindow = array_values(array_filter($seeded, static fn (string $date): bool => $date >= $opensOn));

    // The fixture only says something while the fallback window holds exactly
    // the two occurrences the detectors' gate needs: one fewer clusters
    // nothing, one more would pass whatever window either side had opened.
    expect($inWindow)->toHaveCount(2)
        ->and(dwbObservedDates($this->db, $expenseUser->id))->toBe($inWindow)
        ->and(dwbObservedDates($this->db, $incomeUser->id))->toBe($inWindow);
});
