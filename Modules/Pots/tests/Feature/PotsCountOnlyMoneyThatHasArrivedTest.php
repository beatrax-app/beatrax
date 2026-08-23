<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Pots\Public\Enums\PotStatus;
use Modules\Pots\Public\Services\PotBalanceQuery;

// Pots reported EUR 7,041.90 against a dashboard reading EUR 4,191.90 for the
// same account at the same moment. The gap was a salary dated three weeks out:
// an envelope cannot hold money the account has not received, and counting it
// left isOverAllocated false while the account could not cover its own pots.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'pots-arrived',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);

    $hex = bin2hex(random_bytes(4));
    $this->accountId = $this->db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'Arrived ASN',
        'slug' => 'arrived-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00ARR'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function potsArrivedTransaction(DatabaseManager $db, int $userId, int $accountId, string $postedAt, int $amountMinor): void
{
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/pa-'.$hex.'.csv',
        'sha256' => hash('sha256', 'pa-'.$hex),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'pa-fp-'.$hex),
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => 'pa',
        'counterparty_name' => 'PA',
        'normalization_version' => 1,
        'description' => 'pots-arrived fixture',
        'type' => TransactionType::Income->value,
        'source_format' => 'asn-csv',
        'source_row_index' => $row,
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

it('leaves a payment dated after today out of the real balance', function (): void {
    potsArrivedTransaction($this->db, $this->user->id, $this->accountId, '2026-08-20', 419_190);
    potsArrivedTransaction($this->db, $this->user->id, $this->accountId, '2026-09-15', 285_000);

    $row = app(PotBalanceQuery::class)->reconciliationForAccount($this->accountId, $this->user);

    expect($row->realBalanceMinor)->toBe(419_190);
});

// The guard exists to say "these envelopes claim more than the account holds".
// Money still in transit satisfied the claim on paper and kept it quiet.
it('calls an account over-allocated when only the future payment would cover its pots', function (): void {
    potsArrivedTransaction($this->db, $this->user->id, $this->accountId, '2026-08-20', 100_000);
    potsArrivedTransaction($this->db, $this->user->id, $this->accountId, '2026-09-15', 285_000);

    $potId = $this->db->connection()->table('pots')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $this->accountId,
        'name' => 'Japan trip',
        'currency' => Currency::Eur->value,
        'status' => PotStatus::Active->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $this->db->connection()->table('pot_movements')->insert([
        'user_id' => $this->user->id,
        'pot_id' => $potId,
        'amount_minor' => 150_000,
        'currency' => Currency::Eur->value,
        'kind' => 'fund',
        'created_at' => '2026-08-21 00:00:00',
        'updated_at' => '2026-08-21 00:00:00',
    ]);

    $row = app(PotBalanceQuery::class)->reconciliationForAccount($this->accountId, $this->user);

    expect($row->realBalanceMinor)->toBe(100_000)
        ->and($row->allocatedMinor)->toBe(150_000)
        ->and($row->unallocatedMinor)->toBe(-50_000)
        ->and($row->isOverAllocated)->toBeTrue();
});
