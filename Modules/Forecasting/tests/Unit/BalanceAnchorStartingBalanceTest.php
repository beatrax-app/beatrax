<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\BalanceAnchorResolver;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');

    $this->user = User::query()->create([
        'username' => 'basb-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function basbAccount(DatabaseManager $db, int $userId, array $overrides = []): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId(array_merge([
        'user_id' => $userId,
        'name' => 'BASB ASN',
        'slug' => 'basb-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00BASB'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ], $overrides));
}

function basbTransaction(DatabaseManager $db, int $userId, int $accountId, string $postedAt, int $amountMinor): void
{
    static $basbRow = 0;
    $basbRow++;

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/basb-'.$basbRow.'.csv',
        'sha256' => hash('sha256', 'basb-run-'.$basbRow.'-'.bin2hex(random_bytes(4))),
        'uploaded_at' => $postedAt.' 00:00:00',
        'status' => 'imported',
        'created_at' => $postedAt.' 00:00:00',
        'updated_at' => $postedAt.' 00:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'type' => $amountMinor >= 0 ? 'income' : 'expense',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 00:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_name' => 'BASB Merchant '.$basbRow,
        'counterparty_normalized' => 'basb merchant '.$basbRow,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'source_row_index' => $basbRow,
        'fingerprint' => hash('sha256', 'basb-tx-'.$basbRow.'-'.bin2hex(random_bytes(4))),
        'fingerprint_version' => 3,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => $postedAt.' 00:00:00',
        'updated_at' => $postedAt.' 00:00:00',
    ]);
}

it('anchors the transactions-sum fallback on a dateless baseline, still ignoring the future', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $accountId = basbAccount($db, $this->user->id, ['starting_balance_minor' => 285_000]);

    basbTransaction($db, $this->user->id, $accountId, '2026-06-10', -1_000);
    basbTransaction($db, $this->user->id, $accountId, '2026-06-20', 50_000);

    $anchor = app(BalanceAnchorResolver::class)->forAccount($accountId, $this->user);

    expect($anchor->source)->toBe('sum_of_transactions');
    expect($anchor->openingBalanceMinor)->toBe(284_000);
});

it('does not re-count history a dated baseline already holds', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $accountId = basbAccount($db, $this->user->id, [
        'starting_balance_minor' => 100_000,
        'starting_balance_date' => '2026-06-05',
    ]);

    basbTransaction($db, $this->user->id, $accountId, '2026-06-01', -5_000);
    basbTransaction($db, $this->user->id, $accountId, '2026-06-05', -1_000);
    basbTransaction($db, $this->user->id, $accountId, '2026-06-10', -2_000);

    $anchor = app(BalanceAnchorResolver::class)->forAccount($accountId, $this->user);

    expect($anchor->openingBalanceMinor)->toBe(97_000);
});

it('still sums bare history for an account carrying no baseline', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $accountId = basbAccount($db, $this->user->id);

    basbTransaction($db, $this->user->id, $accountId, '2026-06-01', -5_000);
    basbTransaction($db, $this->user->id, $accountId, '2026-06-10', -2_000);

    $anchor = app(BalanceAnchorResolver::class)->forAccount($accountId, $this->user);

    expect($anchor->openingBalanceMinor)->toBe(-7_000);
});
