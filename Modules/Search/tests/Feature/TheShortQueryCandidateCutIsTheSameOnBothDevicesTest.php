<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Search\Internal\Services\FtsCandidateResolver;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

// A needle under three characters takes the LIKE arm, and that arm caps the
// rows it answers out of at 500. The cap ended on `transactions.id`, so which
// 500 of 501 matches a short query is answered from was decided by a number
// each device counts for itself.

function shortCutSearchHits(int $userId, string $needle): array
{
    /** @var SearchQuery $search */
    $search = app(SearchQuery::class);

    $ids = [];
    foreach ($search->search(User::findOrFail($userId), $needle, SearchFilters::empty())->rows as $row) {
        $ids[] = $row->id;
    }

    return $ids;
}

/**
 * One day's worth of matches, more than the cap admits, with `booked_at`
 * running the opposite way to the ids: the newest instant is the lowest id and
 * the oldest instant is the highest, so the two clauses cut opposite ends.
 *
 * @return list<int>
 */
function shortCutSeedOneDayOfMatches(int $userId, int $howMany): array
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = (int) DB::table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Short cut bank '.$suffix,
        'slug' => 'short-cut-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = (int) DB::table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/short-cut-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'short-cut-'.$suffix),
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
            'fingerprint' => hash('sha256', 'short-cut-'.$suffix.'-'.$i),
            'fingerprint_version' => 3,
            'posted_at' => '2026-06-05',
            'booked_at' => '2026-06-05 '.str_pad((string) intdiv($howMany - $i, 60), 2, '0', STR_PAD_LEFT).':'.str_pad((string) (($howMany - $i) % 60), 2, '0', STR_PAD_LEFT).':00',
            'value_date' => '2026-06-05',
            'type' => 'expense',
            'amount_minor' => -100 - $i,
            'currency' => 'EUR',
            'settled_amount_minor' => -100 - $i,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Zq Bakery',
            'counterparty_normalized' => 'zq bakery',
            'normalization_version' => 1,
            'description' => 'a zq loaf',
            'source_format' => 'asn-csv',
            'source_row_index' => $i,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('transactions')->insert($chunk);
    }

    $ids = DB::table('transactions')
        ->where('user_id', $userId)
        ->where('import_run_id', $runId)
        ->orderBy('id')
        ->pluck('id')
        ->all();

    $docs = [];

    foreach ($ids as $id) {
        $docs[] = ['transaction_id' => (int) $id, 'user_id' => $userId, 'search_body' => 'zq bakery a zq loaf'];
    }

    foreach (array_chunk($docs, 100) as $chunk) {
        DB::table('transaction_search_docs')->insert($chunk);
    }

    return array_map(static fn (mixed $id): int => (int) $id, $ids);
}

it('answers a two-character needle out of the same 500 matches on both devices', function (): void {
    $userId = $this->searchTestUser('short-cut-'.bin2hex(random_bytes(3)));

    $ids = shortCutSeedOneDayOfMatches($userId, FtsCandidateResolver::LIKE_FALLBACK_CANDIDATE_CAP + 1);

    expect($ids)->toHaveCount(FtsCandidateResolver::LIKE_FALLBACK_CANDIDATE_CAP + 1);

    $oldestInstant = $ids[count($ids) - 1];
    $secondOldest = $ids[count($ids) - 2];
    $hits = shortCutSearchHits($userId, 'zq');

    // The denominator. Both clauses admit this row, and the page the reader is
    // handed is the highest ids of whatever the cap admitted — so an absence
    // below is the cap, not a fixture that never matched or a page it missed.
    expect($hits)->toContain($secondOldest);

    // Highest id, oldest instant: ranked first by `transactions.id desc` and
    // last by the clause both devices compute. It is the one row of 501 the
    // cap drops, and the page it would have headed shows it or does not.
    expect($hits)->not->toContain($oldestInstant);
});
