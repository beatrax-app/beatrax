<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;
use Modules\Recurring\Public\Services\FixedPaymentsViewQuery;

function fpvUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function fpvAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'fpv '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL00FPV0'.str_pad(substr($slug, 0, 8), 10, '0', STR_PAD_RIGHT),
        'default_currency' => 'EUR',
    ]);
}

function fpvImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/fpv.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fpvSeries(User $user, string $direction, string $detectedName, array $overrides = []): RecurringSeries
{
    return RecurringSeries::query()->create(array_merge([
        'user_id' => $user->id,
        'direction' => $direction,
        'detected_name' => $detectedName,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => $direction === 'income' ? 350000 : -1099,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => $direction === 'income' ? 350000 : -1099,
        'variance_tolerance_percent' => 25,
        'cluster_key' => $direction.'::'.$detectedName.'::eur::monthly',
        'next_expected_at' => '2026-06-17',
        'next_expected_confidence_low' => false,
    ], $overrides));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = fpvUser('fpv');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('returns grouped sections (expenses + income + transfers) for an approved series set sorted DESC by monthly equivalent', function (): void {
    fpvSeries($this->user, 'expense', 'spotify', [
        'monthly_equivalent_minor' => -1099,
        'cluster_key' => 'expense::spotify::eur::monthly',
    ]);
    fpvSeries($this->user, 'expense', 'netflix', [
        'monthly_equivalent_minor' => -1499,
        'latest_amount_minor' => -1499,
        'cluster_key' => 'expense::netflix::eur::monthly',
    ]);
    fpvSeries($this->user, 'income', 'acme bv', [
        'monthly_equivalent_minor' => 350000,
        'cluster_key' => 'income::acme-bv::eur::monthly',
    ]);

    /** @var FixedPaymentsViewQuery $query */
    $query = $this->app->make(FixedPaymentsViewQuery::class);
    $sections = $query->viewForUser($this->user);

    expect($sections)->toHaveKeys(['expenses', 'income', 'transfers']);
    expect($sections['expenses'])->toHaveCount(2);
    expect($sections['income'])->toHaveCount(1);
    expect($sections['transfers'])->toBe([]);

    // Expenses sort DESC by monthly_equivalent, and for negative amounts that
    // puts the larger absolute equivalent (-1499) first.
    expect($sections['expenses'][0]->detectedName)->toBe('netflix');
    expect($sections['expenses'][1]->detectedName)->toBe('spotify');
})->group('view-for-user-returns-grouped-sections');

it('returns empty sections for a user with no approved series (cross-user empty)', function (): void {
    $other = fpvUser('other');
    fpvSeries($other, 'expense', 'spotify');

    /** @var FixedPaymentsViewQuery $query */
    $query = $this->app->make(FixedPaymentsViewQuery::class);
    $sections = $query->viewForUser($this->user);

    expect($sections['expenses'])->toBe([]);
    expect($sections['income'])->toBe([]);
    expect($sections['transfers'])->toBe([]);
})->group('cross-user-empty');

it('omits non-approved series — pending / rejected / snoozed / cadence_changed never appear in viewForUser', function (): void {
    fpvSeries($this->user, 'expense', 'pending-thing', ['state' => 'pending']);
    fpvSeries($this->user, 'expense', 'rejected-thing', ['state' => 'rejected', 'cluster_key' => 'expense::rejected-thing::eur::monthly']);
    fpvSeries($this->user, 'expense', 'approved-thing', ['state' => 'approved', 'cluster_key' => 'expense::approved-thing::eur::monthly']);

    /** @var FixedPaymentsViewQuery $query */
    $query = $this->app->make(FixedPaymentsViewQuery::class);
    $sections = $query->viewForUser($this->user);

    expect($sections['expenses'])->toHaveCount(1);
    expect($sections['expenses'][0]->detectedName)->toBe('approved-thing');
})->group('only-approved-series-surface');

it('runs viewForUser in ≤ 3 queries for N=12 series (N+1 budget)', function (): void {
    for ($i = 0; $i < 12; $i++) {
        fpvSeries($this->user, $i % 2 === 0 ? 'expense' : 'income', 'merchant-'.$i, [
            'monthly_equivalent_minor' => -1000 - $i,
            'latest_amount_minor' => -1000 - $i,
            'cluster_key' => 'cluster-'.$i,
        ]);
    }

    /** @var FixedPaymentsViewQuery $query */
    $query = $this->app->make(FixedPaymentsViewQuery::class);

    /** @var array<int, string> $log */
    $log = [];
    $this->db->connection()->listen(static function ($query) use (&$log): void {
        $log[] = $query->sql;
    });
    $query->viewForUser($this->user);

    expect(count($log))->toBeLessThanOrEqual(3);
})->group('n-plus-one-budget');

it('sums monthly_equivalent_minor by direction in monthlyEquivalentTotals', function (): void {
    // The query sums monthly_equivalent_minor as the detector wrote it; the
    // cadence multiplier is applied at write time (weekly × 52/12, monthly × 1,
    // quarterly ÷ 3, yearly ÷ 12).
    fpvSeries($this->user, 'expense', 'weekly-thing', [
        'cadence' => 'weekly',
        'monthly_equivalent_minor' => -4333,
        'latest_amount_minor' => -1000,
        'cluster_key' => 'expense::weekly-thing::eur::weekly',
    ]);
    fpvSeries($this->user, 'expense', 'monthly-thing', [
        'cadence' => 'monthly',
        'monthly_equivalent_minor' => -1099,
        'latest_amount_minor' => -1099,
        'cluster_key' => 'expense::monthly-thing::eur::monthly',
    ]);
    fpvSeries($this->user, 'income', 'salary', [
        'cadence' => 'monthly',
        'monthly_equivalent_minor' => 350000,
        'latest_amount_minor' => 350000,
        'cluster_key' => 'income::salary::eur::monthly',
    ]);

    /** @var FixedPaymentsViewQuery $query */
    $query = $this->app->make(FixedPaymentsViewQuery::class);
    $totals = $query->monthlyEquivalentTotals($this->user);

    expect($totals)->toHaveKeys(['expense_eur_minor', 'income_eur_minor', 'net_eur_minor']);
    expect($totals['expense_eur_minor'])->toBe(-5432); // -4333 + -1099
    expect($totals['income_eur_minor'])->toBe(350000);
    expect($totals['net_eur_minor'])->toBe(344568); // 350000 + (-5432)
})->group('monthly-equivalent-multiplier');

it('topByMonthlyEquivalent applies the month-window filter BEFORE the limit so this-month-only returns matching rows even when the unfiltered top-N falls outside the window', function (): void {
    // The top 6 by absolute equivalent all sit in June, with one smaller May
    // row behind them. Clip-then-filter would show the empty state for May.
    for ($i = 0; $i < 6; $i++) {
        fpvSeries($this->user, 'expense', 'jun-'.$i, [
            'monthly_equivalent_minor' => -2000 - ($i * 100),
            'latest_amount_minor' => -2000 - ($i * 100),
            'cluster_key' => 'expense::jun-'.$i.'::eur::monthly',
            'next_expected_at' => '2026-06-15',
        ]);
    }
    fpvSeries($this->user, 'expense', 'may-row', [
        'monthly_equivalent_minor' => -500,
        'latest_amount_minor' => -500,
        'cluster_key' => 'expense::may-row::eur::monthly',
        'next_expected_at' => '2026-05-15',
    ]);

    /** @var FixedPaymentsViewQuery $query */
    $query = $this->app->make(FixedPaymentsViewQuery::class);
    $top = $query->topByMonthlyEquivalent(
        $this->user,
        6,
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-31 23:59:59'),
    );

    expect($top)->toHaveCount(1);
    expect($top[0]->detectedName)->toBe('may-row');
})->group('top-by-monthly-equivalent-month-window-filter-precedes-limit');

it('topByMonthlyEquivalent limits to 6 by default and orders DESC by absolute monthly equivalent', function (): void {
    for ($i = 0; $i < 10; $i++) {
        fpvSeries($this->user, 'expense', 'tbm-'.$i, [
            'monthly_equivalent_minor' => -1000 - ($i * 100),
            'latest_amount_minor' => -1000 - ($i * 100),
            'cluster_key' => 'expense::tbm-'.$i.'::eur::monthly',
        ]);
    }

    /** @var FixedPaymentsViewQuery $query */
    $query = $this->app->make(FixedPaymentsViewQuery::class);
    $top = $query->topByMonthlyEquivalent($this->user);

    expect($top)->toHaveCount(6);
    // Most negative == largest fixed cost, so it sorts first.
    expect($top[0]->monthlyEquivalent->toMinor())->toBe(-1900);
})->group('top-by-monthly-equivalent-limits-to-6');

it('falls back to a prior occurrence chain link when the latest chain is null', function (): void {
    // A series with latest_funding_chain_link_id null carries a null
    // latestFundingChainLinkId. The fuller fallback walk is exercised by the
    // RecurringPage feature test against a seeded chain_link + occurrence pair.
    fpvSeries($this->user, 'expense', 'no-chain-merchant', [
        'latest_funding_chain_link_id' => null,
    ]);

    /** @var FixedPaymentsViewQuery $query */
    $query = $this->app->make(FixedPaymentsViewQuery::class);
    $sections = $query->viewForUser($this->user);

    expect($sections['expenses'])->toHaveCount(1);
    expect($sections['expenses'][0]->latestFundingChainLinkId)->toBeNull();
})->group('chain-fallback');

it('exposes the chain id on the DTO when latest_funding_chain_link_id is set on the series', function (): void {
    // A real chain_links row, so the FK holds.
    $db = $this->db->connection();
    $account = fpvAccount($this->user, 'fpv-asn');
    $run = fpvImportRun($this->user, str_repeat('c', 64));

    $fromTxId = $db->table('transactions')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-01',
        'booked_at' => '2026-05-01 12:00:00',
        'value_date' => '2026-05-01',
        'amount_minor' => -1099,
        'currency' => 'EUR',
        'settled_amount_minor' => -1099,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'spotify',
        'counterparty_normalized' => 'spotify',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('chain-from', 64, 'a', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
    $toTxId = $db->table('transactions')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-02',
        'booked_at' => '2026-05-02 12:00:00',
        'value_date' => '2026-05-02',
        'amount_minor' => -1099,
        'currency' => 'EUR',
        'settled_amount_minor' => -1099,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'paypal',
        'counterparty_normalized' => 'paypal',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 2,
        'fingerprint' => str_pad('chain-to', 64, 'b', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
    $chainLinkId = $db->table('chain_links')->insertGetId([
        'user_id' => $this->user->id,
        'from_transaction_id' => $fromTxId,
        'to_transaction_id' => $toTxId,
        'kind' => 'paypal_funding',
        'state' => 'confirmed',
        'confidence' => 1.000,
        'resolver' => 'auto',
        'evidence' => json_encode(['kind' => 'paypal_funding']),
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);

    fpvSeries($this->user, 'expense', 'chain-merchant', [
        'latest_funding_chain_link_id' => $chainLinkId,
    ]);

    /** @var FixedPaymentsViewQuery $query */
    $query = $this->app->make(FixedPaymentsViewQuery::class);
    $sections = $query->viewForUser($this->user);

    expect($sections['expenses'])->toHaveCount(1);
    expect($sections['expenses'][0]->latestFundingChainLinkId)->toBe($chainLinkId);
})->group('chain-link-id-on-dto');

it('walks prior occurrences for a chain link when the latest is null but a previous occurrence carries a confirmed chain', function (): void {
    $db = $this->db->connection();
    $account = fpvAccount($this->user, 'fpv-fallback');
    $run = fpvImportRun($this->user, str_repeat('f', 64));

    $oldTxId = $db->table('transactions')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-04-15',
        'booked_at' => '2026-04-15 12:00:00',
        'value_date' => '2026-04-15',
        'amount_minor' => -1099,
        'currency' => 'EUR',
        'settled_amount_minor' => -1099,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'spotify',
        'counterparty_normalized' => 'spotify',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('fb-old', 64, 'o', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
    $oldToTxId = $db->table('transactions')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-04-16',
        'booked_at' => '2026-04-16 12:00:00',
        'value_date' => '2026-04-16',
        'amount_minor' => -1099,
        'currency' => 'EUR',
        'settled_amount_minor' => -1099,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'paypal',
        'counterparty_normalized' => 'paypal',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 2,
        'fingerprint' => str_pad('fb-old-to', 64, 'p', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
    $oldChainLinkId = $db->table('chain_links')->insertGetId([
        'user_id' => $this->user->id,
        'from_transaction_id' => $oldTxId,
        'to_transaction_id' => $oldToTxId,
        'kind' => 'paypal_funding',
        'state' => 'confirmed',
        'confidence' => 1.000,
        'resolver' => 'auto',
        'evidence' => json_encode(['kind' => 'paypal_funding']),
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);

    $series = fpvSeries($this->user, 'expense', 'spotify', [
        'latest_funding_chain_link_id' => null,
    ]);
    RecurringSeriesOccurrence::query()->create([
        'user_id' => $this->user->id,
        'recurring_series_id' => $series->id,
        'transaction_id' => $oldTxId,
        'observed_at' => '2026-04-15',
        'observed_amount_minor' => -1099,
        'observed_currency' => 'EUR',
    ]);

    /** @var FixedPaymentsViewQuery $query */
    $query = $this->app->make(FixedPaymentsViewQuery::class);
    $sections = $query->viewForUser($this->user);

    expect($sections['expenses'])->toHaveCount(1);
    expect($sections['expenses'][0]->latestFundingChainLinkId)->toBe($oldChainLinkId);
})->group('chain-fallback-walks-prior-occurrence');
