<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Search\Internal\Services\EntityNameSearch;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

// The arm a needle lands in is picked by its LENGTH -- under three characters
// it cannot be a trigram token, so it runs as a LIKE. SQLite folds ASCII there
// and the whole of Unicode in FTS5, so one word reached different rows at two
// characters and at three, and nothing on the screen said why.

/**
 * @return list<int>
 */
function foldingHits(int $userId, string $needle): array
{
    /** @var SearchQuery $search */
    $search = app(SearchQuery::class);

    $ids = [];
    foreach ($search->search(User::findOrFail($userId), $needle, SearchFilters::empty())->rows as $row) {
        $ids[] = $row->id;
    }

    sort($ids);

    return $ids;
}

function foldingUser(string $prefix): int
{
    return app(DatabaseManager::class)->connection()->table('users')->insertGetId([
        'username' => $prefix.'-'.bin2hex(random_bytes(3)),
        'password' => bcrypt('test'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('reaches an accented counterparty by the same word at two characters and at three', function (): void {
    $userId = foldingUser('fold-ork');

    $txId = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'MÖRK BAR',
        'counterparty_normalized' => 'mork bar',
        'description' => 'a nightcap',
    ]);

    // The denominator: at three characters the needle is a trigram token and
    // FTS5 folds it over the whole of Unicode, so this arm always found the row.
    expect(foldingHits($userId, 'örk'))->toContain($txId);

    // The same word one character shorter takes the LIKE arm, which folded
    // ASCII and left the Ö where the reader's ö could not reach it.
    expect(foldingHits($userId, 'ör'))->toContain($txId);

    expect(foldingHits($userId, 'ör'))->toBe(foldingHits($userId, 'örk'));
});

it('reaches a Cyrillic note by the same word at two characters and at three', function (): void {
    $userId = foldingUser('fold-cyr');

    $txId = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Generic Vendor',
        'counterparty_normalized' => 'generic vendor',
        'description' => 'a transfer',
        'note' => 'ЩУКА for the boat',
    ]);

    expect(foldingHits($userId, 'щук'))->toContain($txId);
    expect(foldingHits($userId, 'щу'))->toContain($txId);

    expect(foldingHits($userId, 'щу'))->toBe(foldingHits($userId, 'щук'));
});

it('reaches a Greek description by the same word at two characters and at three', function (): void {
    $userId = foldingUser('fold-gr');

    $txId = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'ΑΘΗΝΑ TAVERNA',
        'counterparty_normalized' => 'athina taverna',
        'description' => 'dinner',
    ]);

    expect(foldingHits($userId, 'αθη'))->toContain($txId);
    expect(foldingHits($userId, 'αθ'))->toContain($txId);

    expect(foldingHits($userId, 'αθ'))->toBe(foldingHits($userId, 'αθη'));
});

// The positive control. An ASCII needle reached both arms before this change,
// so a run where every case above passes for the wrong reason -- a fixture that
// matches nothing, a user with no rows -- fails here instead of reading green.
it('still reaches an ASCII counterparty at both lengths', function (): void {
    $userId = foldingUser('fold-ascii');

    $txId = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'ZQBER SHOP',
        'counterparty_normalized' => 'zqber shop',
        'description' => 'a control',
    ]);

    expect(foldingHits($userId, 'zqb'))->toContain($txId);
    expect(foldingHits($userId, 'zq'))->toContain($txId);
});

// The palette's other half, matched in SQL rather than in PHP: the counterparty
// section already folded the reader's word with mb_strtolower(), while the goal,
// pot, category and recurring sections went through LikeNeedle and did not.
it('reaches an accented goal by the reader own lower-case spelling', function (): void {
    $userId = foldingUser('fold-goal');
    $user = User::findOrFail($userId);

    app(DatabaseManager::class)->connection()->table('goals')->insert([
        'user_id' => $userId,
        'name' => 'Ölkanne',
        'target_minor' => 500000,
        'target_currency' => 'EUR',
        'start_date' => '2026-01-01',
        'target_date' => '2026-12-31',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $hit = collect(app(EntityNameSearch::class)->query($user, 'ölkanne'))
        ->firstWhere('type', 'goal');

    expect($hit)->not->toBeNull()
        ->and($hit['label'])->toBe('Ölkanne');
});
