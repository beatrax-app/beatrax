<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Search\Internal\Services\FtsCandidateResolver;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

// A needle under three characters cannot be an FTS5 trigram token, so it takes
// the LIKE arm. That arm read two decrypted columns of `transactions` while the
// index it stands in for is built from the notes and the split legs too -- and
// it bounded the rows it READ rather than the rows it MATCHED.

/**
 * @return list<int>
 */
function tlqHits(int $userId, string $needle): array
{
    /** @var SearchQuery $search */
    $search = app(SearchQuery::class);

    $ids = [];
    foreach ($search->search(User::findOrFail($userId), $needle, SearchFilters::empty())->rows as $row) {
        $ids[] = $row->id;
    }

    return $ids;
}

// Enough rows newer than the subject to fill the fallback's whole window, so a
// match behind them is reachable only by a bound on hits. They carry no index
// doc because no arm is being asked to match them, only to look past them.
function tlqFillNewerRows(int $userId, int $howMany): void
{
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Decoy account',
        'slug' => 'tlq-decoy-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = DB::table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tlq-decoy-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'tlq-decoy-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rows = [];
    for ($i = 0; $i < $howMany; $i++) {
        $rows[] = [
            'user_id' => $userId,
            'account_id' => $accountId,
            'import_run_id' => $runId,
            'fingerprint' => hash('sha256', 'tlq-decoy-'.$i.'-'.bin2hex(random_bytes(6))),
            'fingerprint_version' => 3,
            'posted_at' => '2026-06-01',
            'booked_at' => '2026-06-01 00:00:00',
            'value_date' => '2026-06-01',
            'type' => 'expense',
            'amount_minor' => -100 - $i,
            'currency' => 'EUR',
            'settled_amount_minor' => -100 - $i,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Later Shop',
            'counterparty_normalized' => 'later shop',
            'normalization_version' => 1,
            'description' => 'a later purchase',
            'source_format' => 'asn-csv',
            'source_row_index' => $i,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('transactions')->insert($chunk);
    }
}

it('finds the reader own note by two characters, the length that picks the arm', function (): void {
    $userId = $this->searchTestUser('tlq-note-'.bin2hex(random_bytes(3)));

    $txId = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Generic Vendor',
        'counterparty_normalized' => 'generic vendor',
        'description' => 'Office purchase',
        'note' => 'qzx reserve for the boiler',
    ]);

    // The denominator. At three characters the same word reaches the same row
    // through FTS5, so a miss below is the arm, not the fixture.
    expect(tlqHits($userId, 'qzx'))->toContain($txId);

    expect(tlqHits($userId, 'qz'))->toContain($txId);
});

it('finds a split leg note by two characters', function (): void {
    $userId = $this->searchTestUser('tlq-split-'.bin2hex(random_bytes(3)));

    $txId = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Shared Bill Co',
        'counterparty_normalized' => 'shared bill co',
        'description' => 'the whole bill',
    ]);

    $categoryId = DB::table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Household',
        'slug' => 'tlq-household-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('transaction_splits')->insert([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'category_id' => $categoryId,
        'settled_amount_minor' => -2495,
        'settled_currency' => 'EUR',
        'note' => 'qjv my half of it',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A leg written after the parent only enters the body on a re-index.
    $this->seedFtsIndex($txId, $userId);

    expect(tlqHits($userId, 'qjv'))->toContain($txId);

    expect(tlqHits($userId, 'qj'))->toContain($txId);
});

it('finds the tax note by two characters', function (): void {
    $userId = $this->searchTestUser('tlq-tax-'.bin2hex(random_bytes(3)));

    $txId = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Office Supplier',
        'counterparty_normalized' => 'office supplier',
        'description' => 'a desk',
    ]);

    DB::table('tax_transaction_tags')->insert([
        'transaction_id' => $txId,
        'user_id' => $userId,
        'note' => 'qwb claimed against the studio',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->seedFtsIndex($txId, $userId);

    expect(tlqHits($userId, 'qwb'))->toContain($txId);

    expect(tlqHits($userId, 'qw'))->toContain($txId);
});

it('reaches a two-character match older than a full window of newer rows', function (): void {
    $userId = $this->searchTestUser('tlq-cap-'.bin2hex(random_bytes(3)));

    $txId = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Qv Bakery',
        'counterparty_normalized' => 'qv bakery',
        'description' => 'an old loaf',
        'posted_at' => '2019-01-01',
        'booked_at' => '2019-01-01 00:00:00',
        'value_date' => '2019-01-01',
    ]);

    tlqFillNewerRows($userId, FtsCandidateResolver::LIKE_FALLBACK_CANDIDATE_CAP);

    expect(tlqHits($userId, 'qv'))->toContain($txId);
});
