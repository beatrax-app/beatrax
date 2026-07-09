<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Sync\Internal\Crypto\GdkKeyringService;

/*
 * AmountsRemainAggregatableTest — CRYPT-01 / D-02a: with encryption enabled,
 * a raw SQL SUM(amount_minor) + GROUP BY over transactions still returns the
 * correct plaintext totals, proving amounts are NOT encrypted (Pitfall 1 —
 * ≥11 query classes depend on SQL-side aggregation over amount_minor /
 * settled_amount_minor; SQLite cannot aggregate ciphertext).
 * 14-VALIDATION.md CRYPT-01 row 4.
 *
 * RED until Plan 02 ships Modules\Sync\Internal\Crypto\GdkKeyringService
 * (the encryption-enabled precondition this test exercises). This test
 * references the planned production FQCN, which does not yet exist — the
 * failure is "class not found", the correct Wave 0 RED state.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'amounts-aggregatable-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN', 'slug' => 'asn-agg', 'kind' => 'asn',
        'iban' => 'NL57ASNB0123456780', 'default_currency' => 'EUR',
    ]);
    $this->importRun = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/agg.csv',
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);
});

it('sums amount_minor correctly via raw SQL after encryption is enabled for the user', function (): void {
    // Turn encryption ON for this user (group-of-one GDK) BEFORE importing —
    // proves the aggregation path is unaffected by the D-02b content
    // encryption that runs alongside it.
    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $keyring->generateAndPersist((int) $this->user->id, $session);

    $action = $this->app->make(RecordTransactions::class);
    $action([
        $this->canonical([
            'userId' => $this->user->id,
            'accountId' => $this->account->id,
            'importRunId' => $this->importRun->id,
            'amountMinor' => -1299,
            'settledAmountMinor' => -1299,
            'sourceRef' => 'AGG-REF-1',
        ]),
        $this->canonical([
            'userId' => $this->user->id,
            'accountId' => $this->account->id,
            'importRunId' => $this->importRun->id,
            'amountMinor' => -2501,
            'settledAmountMinor' => -2501,
            'sourceRef' => 'AGG-REF-2',
        ]),
    ], $this->user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $total = (int) $db->connection()
        ->table('transactions')
        ->where('user_id', $this->user->id)
        ->sum('amount_minor');

    expect($total)->toBe(-3800);

    $grouped = $db->connection()
        ->table('transactions')
        ->where('user_id', $this->user->id)
        ->selectRaw('account_id, SUM(settled_amount_minor) as total')
        ->groupBy('account_id')
        ->get();

    expect((int) $grouped->first()->total)->toBe(-3800);
});
