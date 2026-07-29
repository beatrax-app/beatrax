<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Dto\SearchResultPage;
use Modules\Search\Public\Services\SearchQuery;

// Wave 0 RED — implemented in Plan 03

/**
 * SearchQueryTest — SRCH-01 + SRCH-02 feature tests.
 *
 * All tests in this file are RED in Wave 0.  They resolve SearchQuery from
 * the container, which does not exist yet.  Each test must FAIL with a
 * binding/resolution error (BindingResolutionException or similar), NOT a
 * parse/collection error.
 *
 * When Plan 03 lands SearchQuery::class these tests will begin to pass.
 *
 * Cross-user isolation assertion (T-08-04): the final test seeds two users
 * and asserts that User A's results never include User B's rows.
 */
beforeEach(function (): void {
    // Seed User A
    $this->userAId = $this->searchTestUser('search-user-a');
    // Seed User B (for isolation test)
    $this->userBId = $this->searchTestUser('search-user-b');
});

// SRCH-01: find by counterparty name
it('it_finds_transactions_by_counterparty_name', function (): void {
    // Wave 0 RED — implemented in Plan 03
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Albert Heijn',
        'counterparty_normalized' => 'albert heijn',
        'description' => 'Weekly groceries',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'Albert Heijn', SearchFilters::empty());

    expect($page->totalCount)->toBeGreaterThan(0);
});

// SRCH-01: find by description
it('it_finds_transactions_by_description', function (): void {
    // Wave 0 RED — implemented in Plan 03
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Unknown Shop',
        'description' => 'coffee and pastry purchase',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'coffee', SearchFilters::empty());

    expect($page->totalCount)->toBeGreaterThan(0);
});

// SRCH-01: find by tax note
it('it_finds_transactions_by_tax_note', function (): void {
    // Wave 0 RED — implemented in Plan 03
    $txId = $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Generic Vendor',
        'description' => 'Office purchase',
    ]);

    // Seed a tax note for this transaction
    DB::table('tax_transaction_tags')->insert([
        'transaction_id' => $txId,
        'user_id' => $this->userAId,
        'note' => 'laptop keyboard for home office',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Re-seed FTS index to include the tax note in the search body.
    // In production this is done by SearchIndexWriter (Plan 02). In Plan 03
    // tests we populate the FTS tables directly (parallel worktree constraint).
    $this->seedFtsIndex($txId, $this->userAId);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'keyboard', SearchFilters::empty());

    expect($page->totalCount)->toBeGreaterThan(0);
});

// SRCH-01: multi-word AND semantics
it('it_applies_and_semantics_to_multi_word_queries', function (): void {
    // Wave 0 RED — implemented in Plan 03

    // This transaction has BOTH words
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Albert Heijn',
        'description' => 'weekly shop heijn',
    ]);

    // This transaction has only one of the words — should NOT match
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Lidl',
        'description' => 'weekly shop',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    // Multi-word query: both "albert" AND "heijn" must match
    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'albert heijn', SearchFilters::empty());

    expect($page->totalCount)->toBe(1);
});

// SRCH-01: amount-shaped queries (D-07)
it('it_matches_amount_queries', function (): void {
    // Wave 0 RED — implemented in Plan 03
    $this->searchTestTransaction($this->userAId, [
        'amount_minor' => -1250,  // €12,50
        'settled_amount_minor' => -1250,
        'counterparty_name' => 'Pharmacy',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    // Amount-shaped query: '12,50' should find the €12.50 transaction
    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, '12,50', SearchFilters::empty());

    expect($page->totalCount)->toBeGreaterThan(0);
});

// SRCH-02: date filter
it('it_filters_by_date_range', function (): void {
    // Wave 0 RED — implemented in Plan 03
    $this->searchTestTransaction($this->userAId, [
        'posted_at' => '2025-06-01',
        'booked_at' => '2025-06-01 00:00:00',
        'counterparty_name' => 'Old Vendor',
    ]);

    $this->searchTestTransaction($this->userAId, [
        'posted_at' => '2026-01-15',
        'booked_at' => '2026-01-15 00:00:00',
        'counterparty_name' => 'New Vendor',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    $filters = new SearchFilters(after: '2026-01-01');
    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'Vendor', $filters);

    expect($page->totalCount)->toBe(1);
});

// SRCH-02: account filter
it('it_filters_by_account', function (): void {
    // Wave 0 RED — implemented in Plan 03
    $db = $this->app->make(DatabaseManager::class)->connection();

    $suffix = bin2hex(random_bytes(4));
    $accountAId = $db->table('accounts')->insertGetId([
        'user_id' => $this->userAId,
        'name' => 'Account A '.$suffix,
        'slug' => 'acct-a-'.$suffix,
        'kind' => 'asn',
        'iban' => 'NL11ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $suffix2 = bin2hex(random_bytes(4));
    $accountBId = $db->table('accounts')->insertGetId([
        'user_id' => $this->userAId,
        'name' => 'Account B '.$suffix2,
        'slug' => 'acct-b-'.$suffix2,
        'kind' => 'ics',
        'iban' => 'NL22INGB'.strtoupper($suffix2),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->table('import_runs')->insertGetId([
        'user_id' => $this->userAId, 'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/acct-filter.csv',
        'sha256' => hash('sha256', 'acct-filter-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(), 'status' => 'committed',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $txAId = $db->table('transactions')->insertGetId([
        'user_id' => $this->userAId, 'account_id' => $accountAId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'a1-'.bin2hex(random_bytes(4))),
        'fingerprint_version' => 3,
        'posted_at' => '2026-01-15', 'booked_at' => '2026-01-15 00:00:00',
        'value_date' => '2026-01-15', 'type' => 'expense',
        'amount_minor' => -100, 'currency' => 'EUR',
        'settled_amount_minor' => -100, 'settled_currency' => 'EUR',
        'counterparty_name' => 'Shared Counterparty',
        'counterparty_normalized' => 'shared counterparty',
        'normalization_version' => 1,
        'description' => 'from account A',
        'source_format' => 'asn-csv', 'source_row_index' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->seedFtsIndex($txAId, $this->userAId);

    $txBId = $db->table('transactions')->insertGetId([
        'user_id' => $this->userAId, 'account_id' => $accountBId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'b1-'.bin2hex(random_bytes(4))),
        'fingerprint_version' => 3,
        'posted_at' => '2026-01-15', 'booked_at' => '2026-01-15 00:00:00',
        'value_date' => '2026-01-15', 'type' => 'expense',
        'amount_minor' => -200, 'currency' => 'EUR',
        'settled_amount_minor' => -200, 'settled_currency' => 'EUR',
        'counterparty_name' => 'Shared Counterparty',
        'counterparty_normalized' => 'shared counterparty',
        'normalization_version' => 1,
        'description' => 'from account B',
        'source_format' => 'asn-csv', 'source_row_index' => 2,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->seedFtsIndex($txBId, $this->userAId);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    $filters = new SearchFilters(accounts: [$accountAId]);
    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'Shared', $filters);

    expect($page->totalCount)->toBe(1);
});

// T-08-06: the account filter validates ids against ownership before it
// applies them. An id belonging to someone else has to collapse the result
// set to empty — the dangerous failure is not an error, it is silently
// dropping the filter and returning everything the caller can otherwise
// see, which is how a foreign id turns into a data leak.
it('returns an empty result for an account id belonging to another user', function (): void {
    $db = app(DatabaseManager::class)->connection();

    $this->searchTestTransaction($this->userBId, [
        'counterparty_name' => 'Shared Counterparty',
        'description' => 'belongs to user B',
    ]);
    $foreignAccountId = (int) $db->table('accounts')->where('user_id', $this->userBId)->value('id');

    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Shared Counterparty',
        'description' => 'belongs to user A',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    $page = $searchQuery->search($user, 'Shared', new SearchFilters(accounts: [$foreignAccountId]));

    expect($page->totalCount)->toBe(0);
});

// SRCH-02: amount min/max filter
it('it_filters_by_amount_min_max', function (): void {
    // Wave 0 RED — implemented in Plan 03
    $this->searchTestTransaction($this->userAId, [
        'amount_minor' => -500, 'settled_amount_minor' => -500,
        'counterparty_name' => 'Cheap Shop',
    ]);
    $this->searchTestTransaction($this->userAId, [
        'amount_minor' => -5000, 'settled_amount_minor' => -5000,
        'counterparty_name' => 'Expensive Shop',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    // Filter: amount between €20 and €200 (excludes the €50 expense but not correct, let's say 1–10)
    $filters = new SearchFilters(amountMin: '1.00', amountMax: '10.00');
    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'Shop', $filters);

    // Only the €5.00 transaction (500 minor) is within [€1, €10]
    expect($page->totalCount)->toBe(1);
});

// SRCH-02: category filter
it('it_filters_by_category', function (): void {
    // Wave 0 RED — implemented in Plan 03
    $db = $this->app->make(DatabaseManager::class)->connection();

    $catId = $db->table('categories')->insertGetId([
        'name' => 'Groceries', 'slug' => 'groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $txId = $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Categorized Vendor',
        'category_id' => $catId,
    ]);

    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Uncategorized Vendor',
        'category_id' => null,
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    $filters = new SearchFilters(categories: [$catId]);
    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'Vendor', $filters);

    expect($page->totalCount)->toBe(1);
    expect($page->rows[0]->id)->toBe($txId);
});

// T-08-04: cross-user isolation
it('user A cannot see user B transactions in search results', function (): void {
    // Wave 0 RED — implemented in Plan 03
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'User A Vendor',
        'description' => 'User A only transaction',
    ]);

    $this->searchTestTransaction($this->userBId, [
        'counterparty_name' => 'User A Vendor',
        'description' => 'User B private transaction',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $userA = User::findOrFail($this->userAId);

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($userA, 'User A Vendor', SearchFilters::empty());

    // User A can only see their own row
    expect($page->totalCount)->toBe(1)
        ->and(collect($page->rows)->pluck('id'))
        ->each->not->toBe(
            $this->searchTestTransaction($this->userBId, ['counterparty_name' => 'dummy'])
        );
});

// T-08-09 (security): FTS highlight()/snippet() do not HTML-escape the
// surrounding text. SearchQuery must escape it before injecting <mark>, or a
// counterparty/description containing HTML becomes a stored-XSS payload when
// the highlighted markup is rendered with {!! !!} / x-html.
it('escapes HTML in highlighted counterparty and snippet output', function (): void {
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => '<img src=x onerror=alert(1)> Heijnmart',
        'counterparty_normalized' => 'heijnmart',
        'description' => 'weekly <script>alert(2)</script> heijnmart groceries',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'heijnmart', SearchFilters::empty());

    expect($page->rows)->not->toBeEmpty();
    $row = $page->rows[0];

    // The match is wrapped in real <mark> tags...
    expect($row->highlightedCounterparty)->toContain('<mark>');
    // ...but the user-supplied HTML is neutralised, never emitted raw.
    expect($row->highlightedCounterparty)
        ->not->toContain('<img')
        ->toContain('&lt;img');

    // The snippet's truncation window may not include the script token, but
    // whatever it does include must never carry a raw, executable HTML tag.
    if ($row->snippet !== null) {
        expect($row->snippet)
            ->not->toContain('<script')
            ->not->toContain('<img');
    }
});

// CR-01 (regression): a 2-char query and a bare-amount query must never emit
// raw `<`/HTML in snippet or highlightedCounterparty. These branches never run
// decorateHighlight (highlight gates at >= 3 chars / FTS path), so snippet must
// stay null and the counterparty must remain the raw string — never marked-up
// unescaped HTML.
it('short and amount queries never emit raw html in snippet output', function (): void {
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => '<b>AB</b> Mart',
        'counterparty_normalized' => 'ab mart',
        'description' => 'tiny <i>note</i> ab',
        'amount_minor' => -202400,       // €2024.00
        'settled_amount_minor' => -202400,
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    // 2-char query (below the FTS highlight threshold) → LIKE fallback, no highlight.
    /** @var SearchResultPage $shortPage */
    $shortPage = $searchQuery->search($user, 'AB', SearchFilters::empty());
    foreach ($shortPage->rows as $row) {
        expect($row->snippet)->toBeNull();
        if ($row->highlightedCounterparty !== null) {
            expect($row->highlightedCounterparty)->not->toContain('<b>')->not->toContain('<i>');
        }
    }

    // Bare numeric (amount) query → amount branch, no FTS highlight.
    /** @var SearchResultPage $amountPage */
    $amountPage = $searchQuery->search($user, '2024', SearchFilters::empty());
    expect($amountPage->totalCount)->toBeGreaterThan(0);
    foreach ($amountPage->rows as $row) {
        expect($row->snippet)->toBeNull();
        if ($row->highlightedCounterparty !== null) {
            expect($row->highlightedCounterparty)->not->toContain('<b>')->not->toContain('<i>');
        }
    }
});

// WR-02: a bare numeric query that also matches as text must NOT OR-in every
// transaction whose amount equals that number. The amount branch only fires
// when the text branch found zero candidates.
it('does not conflate text matches with amount matches for numeric queries', function (): void {
    // A transaction whose DESCRIPTION contains "2024" (text match).
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Yearbook Shop',
        'counterparty_normalized' => 'yearbook shop',
        'description' => 'invoice for 2024 season',
        'amount_minor' => -999, 'settled_amount_minor' => -999,
    ]);

    // An UNRELATED transaction whose AMOUNT is €2024.00 but no "2024" text.
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Big Purchase',
        'counterparty_normalized' => 'big purchase',
        'description' => 'expensive thing',
        'amount_minor' => -202400, 'settled_amount_minor' => -202400,
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    // "2024" matches the text row; the €2024.00 amount row must NOT be ORed in.
    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, '2024', SearchFilters::empty());
    expect($page->totalCount)->toBe(1)
        ->and($page->rows[0]->counterpartyName)->toBe('Yearbook Shop');
});

// WR-03: filters-only search (empty text) must scope by user_id + filters
// directly, NOT by materializing every transaction id into a whereIn.
it('returns filters-only results without materializing all ids', function (): void {
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Jan Vendor',
        'posted_at' => '2026-02-10', 'booked_at' => '2026-02-10 00:00:00',
    ]);
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Old Vendor',
        'posted_at' => '2024-02-10', 'booked_at' => '2024-02-10 00:00:00',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    // Empty query + a date filter — only the 2026 row matches.
    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, '', new SearchFilters(after: '2026-01-01'));
    expect($page->totalCount)->toBe(1)
        ->and($page->rows[0]->counterpartyName)->toBe('Jan Vendor');
});

// WR-01: the summary totals are labelled "€", so non-EUR-settled rows must not
// be summed into the EUR total.
it('summary totals exclude non-eur-settled rows', function (): void {
    // EUR-settled out-row: -€10.00.
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Summary Vendor',
        'counterparty_normalized' => 'summary vendor',
        'amount_minor' => -1000, 'currency' => 'EUR',
        'settled_amount_minor' => -1000, 'settled_currency' => 'EUR',
    ]);
    // USD-settled out-row: must NOT be added to the € total.
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Summary Vendor',
        'counterparty_normalized' => 'summary vendor',
        'amount_minor' => -7443, 'currency' => 'USD',
        'settled_amount_minor' => -7443, 'settled_currency' => 'USD',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'Summary Vendor', SearchFilters::empty());

    // Both rows are counted, but only the EUR row contributes to the € total.
    expect($page->totalCount)->toBe(2)
        ->and($page->totalOutMinor)->toBe(-1000);
});

// WR-08: the advertised amount:/account:/category: tokens must actually apply.
it('applies the amount token as a min filter', function (): void {
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Token Shop',
        'counterparty_normalized' => 'token shop',
        'amount_minor' => -500, 'settled_amount_minor' => -500,
    ]);
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Token Shop',
        'counterparty_normalized' => 'token shop',
        'amount_minor' => -9000, 'settled_amount_minor' => -9000,
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    // amount:>50 → only the €90.00 row (abs >= €50).
    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'Token Shop amount:>50', SearchFilters::empty());
    expect($page->totalCount)->toBe(1)
        ->and(abs($page->rows[0]->amountMinor))->toBe(9000);
});

it('resolves account and category name tokens to ids scoped to the user', function (): void {
    $db = $this->app->make(DatabaseManager::class)->connection();

    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->table('accounts')->insertGetId([
        'user_id' => $this->userAId,
        'name' => 'Vakantiegeld Spaar',
        'slug' => 'vak-'.$suffix,
        'kind' => 'asn',
        'iban' => 'NL33ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $catId = $db->table('categories')->insertGetId([
        'user_id' => $this->userAId,
        'name' => 'Groceries', 'slug' => 'gro-'.$suffix,
        'kind' => 'expense', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Matching row: right account + right category.
    $matchTx = $this->searchTestTransaction($this->userAId, [
        'account_id' => $accountId,
        'category_id' => $catId,
        'counterparty_name' => 'TokenMatch Vendor',
        'counterparty_normalized' => 'tokenmatch vendor',
    ]);

    // Non-matching row: same counterparty, no account/category.
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'TokenMatch Vendor',
        'counterparty_normalized' => 'tokenmatch vendor',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    // Case-insensitive prefix match: "vakantie" → the Vakantiegeld account,
    // "grocer" → the Groceries category.
    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'TokenMatch account:vakantie category:grocer', SearchFilters::empty());
    expect($page->totalCount)->toBe(1)
        ->and($page->rows[0]->id)->toBe($matchTx);
});

// WR-06: on a distance tie the suggester prefers the MORE FREQUENT corpus word,
// and an exact match on a different corpus word does not suppress the suggestion.
it('breaks did-you-mean ties by corpus frequency', function (): void {
    // "kruidvat" appears 3× (more frequent), "kruidvit" once. Both are
    // levenshtein distance 1 from the typo "kruidvet".
    for ($i = 0; $i < 3; $i++) {
        $this->searchTestTransaction($this->userAId, [
            'counterparty_name' => 'Kruidvat',
            'counterparty_normalized' => 'kruidvat',
            'description' => "drogist bezoek {$i}",
        ]);
    }
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Kruidvit',
        'counterparty_normalized' => 'kruidvit',
        'description' => 'andere drogist',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    // The typo matches nothing → suggester runs and prefers the frequent word.
    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'kruidvet', SearchFilters::empty());
    expect($page->totalCount)->toBe(0)
        ->and($page->didYouMean)->toBe('kruidvat');
});
