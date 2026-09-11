<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tax\Internal\Services\TaxYearQuery;
use Modules\Tax\Public\Actions\TagTransaction;
use Modules\Tax\Public\Services\TaxTagQuery;

uses(RefreshDatabase::class);

// TagTransaction refuses a row that can carry no deduction, and that was the
// whole of the rule. Both columns it reads move afterwards -- the reclassify
// picker writes any of the seven types, a peer's retype merges in, a re-import
// stamps payment_type -- and nothing untags, so the total kept counting it.

function retypedTaggedUser(DatabaseManager $db): int
{
    return $db->connection()->table('users')->insertGetId([
        'username' => 'retyped-tagged-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture-password-12chars'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function retypedTaggedRow(DatabaseManager $db, int $userId, int $minor = -20000): int
{
    $suffix = bin2hex(random_bytes(5));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN '.$suffix, 'slug' => 'retyped-'.$suffix,
        'kind' => 'bank', 'iban' => 'NL00RTY'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/retyped-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'retyped-'.$suffix), 'uploaded_at' => now(), 'status' => 'committed',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'retyped-tx-'.$suffix),
        'posted_at' => '2026-04-10', 'booked_at' => '2026-04-10 12:00:00', 'value_date' => '2026-04-10',
        'amount_minor' => $minor, 'currency' => 'EUR',
        'settled_amount_minor' => $minor, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'retyped vendor', 'counterparty_name' => 'Retyped Vendor BV',
        'normalization_version' => 1, 'description' => 'Equipment',
        'type' => 'expense', 'payment_type' => 'unknown', 'source_format' => 'asn-csv',
        'source_row_index' => 1, 'fingerprint_version' => 3,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $this->userId = retypedTaggedUser($db);
    $this->txId = retypedTaggedRow($db, $this->userId);

    app(TagTransaction::class)->execute($this->userId, $this->txId, null, null, null);
});

it('drops a tagged row from the year total once it can no longer carry the tag', function (array $columns): void {
    $this->db->connection()->table('transactions')->where('id', $this->txId)->update($columns);

    $year = app(TaxYearQuery::class)->forUser($this->userId, 2026);

    expect($year->deductionsTotalMinor)->toBe(0)
        ->and($year->itemCount)->toBe(0);
})->with([
    'retyped as the other half of a move between your own accounts' => [['type' => 'transfer_out']],
    'retyped as a correction that reconciles against nobody' => [['type' => 'adjustment']],
    'retyped by hand as the return it turned out to be' => [['type' => 'refund', 'settled_amount_minor' => 20000, 'amount_minor' => 20000]],
    're-imported with the narrative detector marking the return' => [['payment_type' => 'refund']],
]);

it('drops it from the dashboard summary the cockpit is supposed to agree with', function (): void {
    $this->db->connection()->table('transactions')->where('id', $this->txId)->update(['payment_type' => 'refund']);

    $summary = app(TaxTagQuery::class)->summaryForUser($this->userId, 2026);

    expect($summary->totalMinor)->toBe(0)
        ->and($summary->count)->toBe(0);
});

it('stops offering a year whose only tagged row can no longer carry the tag', function (): void {
    expect(app(TaxYearQuery::class)->availableYears($this->userId))->toBe([2026]);

    $this->db->connection()->table('transactions')->where('id', $this->txId)->update(['type' => 'transfer_out']);

    expect(app(TaxYearQuery::class)->availableYears($this->userId))->toBe([]);
});

it('keeps the tag row itself, so retyping back brings it with it', function (): void {
    $this->db->connection()->table('transactions')->where('id', $this->txId)->update(['type' => 'transfer_out']);

    expect($this->db->connection()->table('tax_transaction_tags')->where('transaction_id', $this->txId)->exists())->toBeTrue();

    $this->db->connection()->table('transactions')->where('id', $this->txId)->update(['type' => 'expense']);

    expect(app(TaxYearQuery::class)->forUser($this->userId, 2026)->deductionsTotalMinor)->toBe(20000);
});

it('still counts a row that never stopped being taggable', function (): void {
    expect(app(TaxYearQuery::class)->forUser($this->userId, 2026)->deductionsTotalMinor)->toBe(20000);
});
