<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Search\Public\Contracts\SearchResultsProvider;

// The palette read the hits from one full search and the "See all N" count from
// a second one, so every keystroke ran the FTS5 MATCH, the currency-totals
// aggregation and the rate lookup twice — and a keystroke that found nothing
// built the did-you-mean corpus twice, decrypting up to 2000 rows each time and
// throwing the first answer away.

/**
 * @return list<string>
 */
function paletteKeystrokeSql(User $user, string $query): array
{
    $statements = [];
    DB::listen(static function (QueryExecuted $executed) use (&$statements): void {
        $statements[] = $executed->sql;
    });

    app(SearchResultsProvider::class)->paletteSections($user, $query);

    return $statements;
}

/**
 * @param  list<string>  $statements
 * @return list<string>
 */
function paletteSqlContaining(array $statements, string $needle, ?string $without = null): array
{
    return array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_contains($sql, $needle)
            && ($without === null || ! str_contains($sql, $without)),
    ));
}

beforeEach(function (): void {
    $this->paletteUserId = $this->searchTestUser('palette-runs-once');
    $this->paletteUser = User::query()->findOrFail($this->paletteUserId);
    $this->searchTestTransaction($this->paletteUserId, [
        'counterparty_name' => 'Albert Heijn',
        'description' => 'weekly groceries',
    ]);
});

// The match is the restriction now rather than a step of its own, so one search
// carries it twice: once in the page read and once in the totals read cloned
// from it. Two searches would put it there four times, which is the doubling
// this file exists to catch, and the two counts below stay at one either way.
it('runs one search per keystroke, carrying the match as its restriction', function (): void {
    $statements = paletteKeystrokeSql($this->paletteUser, 'Heijn');

    expect(paletteSqlContaining($statements, 'transaction_search_fts MATCH', 'highlight('))
        ->toHaveCount(2);

    // Pinned beside the count above, because that one alone cannot tell a
    // restriction carried into a second statement from a second search: a
    // keystroke that searched twice would raise this too.
    expect(paletteSqlContaining($statements, 'from "transactions"'))->toHaveCount(2);
});

it('aggregates the currency totals once per keystroke', function (): void {
    $statements = paletteKeystrokeSql($this->paletteUser, 'Heijn');

    expect(paletteSqlContaining($statements, 'total_count'))->toHaveCount(1);
});

it('builds the did-you-mean corpus once on a keystroke that finds nothing', function (): void {
    $statements = paletteKeystrokeSql($this->paletteUser, 'Heijnx');

    expect(paletteSqlContaining($statements, '"counterparty_name" is not null'))
        ->toHaveCount(1);
});
