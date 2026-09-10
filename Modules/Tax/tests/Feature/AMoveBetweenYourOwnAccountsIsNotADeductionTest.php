<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tax\Internal\Services\TaxYearQuery;
use Modules\Tax\Public\Actions\TagTransaction;

uses(RefreshDatabase::class);

// The year total takes abs() of every tagged row that is not typed `income`,
// so whatever gets tagged becomes a positive deduction. Nothing said which
// rows may be tagged: a transfer between the reader's own accounts, a
// correction that reconciles against nobody, and a return of a purchase all
// took the tag and all read as money spent on a deductible thing.

function ownAccountsUser(DatabaseManager $db, string $username): int
{
    return $db->connection()->table('users')->insertGetId([
        'username' => $username,
        'password' => bcrypt('fixture-password-12chars'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function ownAccountsRow(DatabaseManager $db, int $userId, string $type, int $minor, string $paymentType = 'unknown'): int
{
    $suffix = bin2hex(random_bytes(5));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN '.$suffix, 'slug' => 'own-'.$suffix,
        'kind' => 'bank', 'iban' => 'NL00OWN'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/own-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'own-'.$suffix), 'uploaded_at' => now(), 'status' => 'committed',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'own-tx-'.$suffix),
        'posted_at' => '2026-04-10', 'booked_at' => '2026-04-10 12:00:00', 'value_date' => '2026-04-10',
        'amount_minor' => $minor, 'currency' => 'EUR',
        'settled_amount_minor' => $minor, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'own vendor', 'counterparty_name' => 'Own Vendor BV',
        'normalization_version' => 1, 'description' => 'Row under test',
        'type' => $type, 'payment_type' => $paymentType, 'source_format' => 'asn-csv',
        'source_row_index' => 1, 'fingerprint_version' => 3,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $this->userId = ownAccountsUser($db, 'own-accounts-'.bin2hex(random_bytes(3)));
});

it('takes no tax tag on a row whose type can carry no deduction', function (string $type, int $minor, string $paymentType): void {
    $txId = ownAccountsRow($this->db, $this->userId, $type, $minor, $paymentType);

    app(TagTransaction::class)->execute($this->userId, $txId, null, null, null);

    $tagged = $this->db->connection()->table('tax_transaction_tags')
        ->where('transaction_id', $txId)
        ->exists();

    expect($tagged)->toBeFalse();
})->with([
    'money moved out to another of your own accounts' => ['transfer_out', -50000, 'transfer'],
    'the other leg of that move' => ['transfer_in', 50000, 'transfer'],
    'a correction that reconciles against nobody' => ['adjustment', -1200, 'unknown'],
    'a purchase returned, typed as the credit it is' => ['income', 3000, 'refund'],
    'a purchase returned and labelled by hand' => ['refund', 3000, 'unknown'],
]);

it('still tags the rows a return is actually built from', function (string $type, int $minor): void {
    $txId = ownAccountsRow($this->db, $this->userId, $type, $minor);

    app(TagTransaction::class)->execute($this->userId, $txId, null, null, null);

    expect($this->db->connection()->table('tax_transaction_tags')->where('transaction_id', $txId)->exists())->toBeTrue();
})->with([
    'a deductible cost' => ['expense', -12500],
    'taxable income' => ['income', 250000],
    'a bank charge' => ['fee', -350],
]);

it('leaves a transfer out of the deductions total it would have doubled', function (): void {
    $deductible = ownAccountsRow($this->db, $this->userId, 'expense', -12500);
    $transfer = ownAccountsRow($this->db, $this->userId, 'transfer_out', -50000, 'transfer');

    app(TagTransaction::class)->execute($this->userId, $deductible, null, null, null);
    app(TagTransaction::class)->execute($this->userId, $transfer, null, null, null);

    expect(app(TaxYearQuery::class)->forUser($this->userId, 2026)->deductionsTotalMinor)->toBe(12500);
});
