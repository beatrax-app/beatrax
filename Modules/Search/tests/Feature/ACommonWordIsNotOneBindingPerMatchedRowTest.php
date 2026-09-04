<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

// The trigram tokenizer makes an ordinary Dutch word match most of a ledger, so
// the corpus here is many times the page asked for: a fixture the size of one
// page cannot tell a restriction that scales with the MATCH from one that
// scales with the page, which is the whole difference being guarded.
const COMMON_WORD_CORPUS = 60;

const COMMON_WORD_PAGE = 5;

// A ceiling the page can reach and the corpus cannot: the highlight load binds
// its six sentinels plus one rowid per row it renders, and nothing else in a
// search may bind per matched row at all.
const COMMON_WORD_BINDING_CEILING = 18;

/**
 * @return list<array{sql: string, bindings: int}>
 */
function commonWordStatements(callable $run): array
{
    $statements = [];
    DB::listen(static function ($query) use (&$statements): void {
        $statements[] = ['sql' => $query->sql, 'bindings' => count($query->bindings)];
    });

    $run();

    return $statements;
}

beforeEach(function (): void {
    $fixture = $this->seedFixtureUserAndAccount(Currency::Eur->value);
    $this->reader = $fixture['user'];

    for ($i = 0; $i < COMMON_WORD_CORPUS; $i++) {
        $this->searchTestTransaction($this->reader->id, [
            'account_id' => $fixture['account']->id,
            'counterparty_name' => 'Vendor '.$i,
            'counterparty_normalized' => 'vendor '.$i,
            'description' => 'betaling regel '.$i,
            'amount_minor' => -4990,
            'settled_amount_minor' => -4990,
        ]);
    }
});

it('restricts the page to the MATCH itself rather than to a list of matched ids', function (): void {
    $page = null;

    $statements = commonWordStatements(function () use (&$page): void {
        $page = app(SearchQuery::class)->search(
            $this->reader->fresh(),
            'betaling',
            SearchFilters::empty(),
            null,
            null,
            COMMON_WORD_PAGE,
        );
    });

    expect($page?->totalCount)->toBe(COMMON_WORD_CORPUS)
        ->and($page?->rows)->toHaveCount(COMMON_WORD_PAGE);

    $overBound = array_values(array_filter(
        $statements,
        static fn (array $statement): bool => $statement['bindings'] > COMMON_WORD_BINDING_CEILING,
    ));

    expect($overBound)->toBe([]);

    $ledgerReads = array_values(array_filter(
        $statements,
        static fn (array $statement): bool => str_contains($statement['sql'], 'from "transactions"'),
    ));

    expect($ledgerReads)->not->toBeEmpty();

    foreach ($ledgerReads as $read) {
        expect($read['sql'])->toContain('in (select "transaction_search_fts"."rowid"');
    }
})->group('bounded-read');

// The amount branch reads the sentinel the id list used to carry. It has to be
// asked as a question rather than answered by materialising the match, and only
// once the typed text has already parsed as money.
it('asks whether the text matched anything with an exists probe, then takes the amount branch', function (): void {
    $page = null;

    $statements = commonWordStatements(function () use (&$page): void {
        $page = app(SearchQuery::class)->search(
            $this->reader->fresh(),
            '49.90',
            SearchFilters::empty(),
            null,
            null,
            COMMON_WORD_PAGE,
        );
    });

    expect($page?->totalCount)->toBe(COMMON_WORD_CORPUS);

    $probes = array_values(array_filter(
        $statements,
        static fn (array $statement): bool => str_contains($statement['sql'], 'select exists(')
            && str_contains($statement['sql'], 'transaction_search_fts'),
    ));

    expect($probes)->toHaveCount(1)
        ->and($probes[0]['bindings'])->toBeLessThanOrEqual(COMMON_WORD_BINDING_CEILING);
})->group('bounded-read');

it('leaves the short-query fallback narrowing by the ids it decrypted', function (): void {
    $page = null;

    $statements = commonWordStatements(function () use (&$page): void {
        $page = app(SearchQuery::class)->search(
            $this->reader->fresh(),
            'or',
            SearchFilters::empty(),
            null,
            null,
            COMMON_WORD_PAGE,
        );
    });

    expect($page?->totalCount)->toBe(COMMON_WORD_CORPUS);

    $ledgerReads = array_values(array_filter(
        $statements,
        static fn (array $statement): bool => str_contains($statement['sql'], 'from "transactions"'),
    ));

    expect($ledgerReads)->not->toBeEmpty();

    foreach ($ledgerReads as $read) {
        expect($read['sql'])->not->toContain('transaction_search_fts');
    }
})->group('bounded-read');
