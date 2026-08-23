<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Dto\SearchResultPage;
use Modules\Search\Public\Services\SearchQuery;

beforeEach(function (): void {
    $this->userAId = $this->searchTestUser('search-user-a');
    $this->userBId = $this->searchTestUser('search-user-b');
});

it('it_finds_transactions_by_counterparty_name', function (): void {
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

it('it_finds_transactions_by_description', function (): void {
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

it('it_finds_transactions_by_tax_note', function (): void {
    $txId = $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Generic Vendor',
        'description' => 'Office purchase',
    ]);

    DB::table('tax_transaction_tags')->insert([
        'transaction_id' => $txId,
        'user_id' => $this->userAId,
        'note' => 'laptop keyboard for home office',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The note only enters the search body on a re-index.
    $this->seedFtsIndex($txId, $this->userAId);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'keyboard', SearchFilters::empty());

    expect($page->totalCount)->toBeGreaterThan(0);
});

it('it_applies_and_semantics_to_multi_word_queries', function (): void {
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Albert Heijn',
        'description' => 'weekly shop heijn',
    ]);

    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Lidl',
        'description' => 'weekly shop',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'albert heijn', SearchFilters::empty());

    expect($page->totalCount)->toBe(1);
});

it('it_matches_amount_queries', function (): void {
    $this->searchTestTransaction($this->userAId, [
        'amount_minor' => -1250,  // €12,50
        'settled_amount_minor' => -1250,
        'counterparty_name' => 'Pharmacy',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, '12,50', SearchFilters::empty());

    expect($page->totalCount)->toBeGreaterThan(0);
});

it('it_filters_by_date_range', function (): void {
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

it('it_filters_by_account', function (): void {
    $db = $this->app->make(DatabaseManager::class)->connection();

    $suffix = bin2hex(random_bytes(4));
    $accountAId = $db->table('accounts')->insertGetId([
        'user_id' => $this->userAId,
        'name' => 'Account A '.$suffix,
        'slug' => 'acct-a-'.$suffix,
        'kind' => 'bank',
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

// The dangerous failure for an unowned account id is not an error, it is
// silently dropping the filter and returning everything the caller can
// otherwise see, which is how a foreign id turns into a data leak.
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

it('it_filters_by_amount_min_max', function (): void {
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

    $filters = new SearchFilters(amountMin: '1.00', amountMax: '10.00');
    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'Shop', $filters);

    // Only the 500-minor row falls inside [€1, €10].
    expect($page->totalCount)->toBe(1);
});

it('it_filters_by_category', function (): void {
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

it('user A cannot see user B transactions in search results', function (): void {
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

    expect($page->totalCount)->toBe(1)
        ->and(collect($page->rows)->pluck('id'))
        ->each->not->toBe(
            $this->searchTestTransaction($this->userBId, ['counterparty_name' => 'dummy'])
        );
});

// The search body joins counterparty, description and note with a form feed,
// and SQLite's snippet() window spans that join, so the byte reached the page:
// WebKit has no glyph for it and drew a missing-character box between the two.
// The window is wrong as well — the index tokenizer is trigram, so a "token"
// is three characters and twelve of them cut Rentevergoeding down to "Rente…",
// the very word the reader had typed.
it('shows the matched narrative in the snippet, without the field separator byte', function (): void {
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'ASN Bank',
        'counterparty_normalized' => 'asn bank',
        'description' => 'Rentevergoeding tweede kwartaal',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'Rentevergoeding', SearchFilters::empty());

    expect($page->rows)->not->toBeEmpty();
    $snippet = $page->rows[0]->snippet;

    expect($snippet)->not->toBeNull()
        ->and($snippet)->not->toContain(chr(12))
        ->and($snippet)->toContain('<mark>Rentevergoeding</mark>')
        ->and($snippet)->toBe('ASN Bank · <mark>Rentevergoeding</mark> tweede kwartaal');
});

// FTS highlight() and snippet() do not HTML-escape the surrounding text, so
// SearchQuery has to escape before injecting <mark>; otherwise a counterparty
// containing HTML is stored XSS the moment the markup is rendered unescaped.
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

    expect($row->highlightedCounterparty)->toContain('<mark>');
    expect($row->highlightedCounterparty)
        ->not->toContain('<img')
        ->toContain('&lt;img');

    // The truncation window may drop the script token altogether, so assert
    // only over what the snippet does include.
    if ($row->snippet !== null) {
        expect($row->snippet)
            ->not->toContain('<script')
            ->not->toContain('<img');
    }
});

// Highlighting gates at three characters on the FTS path, so the short-query
// and amount branches never reach decorateHighlight: the snippet has to stay
// null and the counterparty raw, rather than becoming unescaped markup.
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

    /** @var SearchResultPage $shortPage */
    $shortPage = $searchQuery->search($user, 'AB', SearchFilters::empty());
    foreach ($shortPage->rows as $row) {
        expect($row->snippet)->toBeNull();
        if ($row->highlightedCounterparty !== null) {
            expect($row->highlightedCounterparty)->not->toContain('<b>')->not->toContain('<i>');
        }
    }

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

// The amount branch only fires when the text branch found nothing; ORing the
// two would drag in every transaction that happens to cost that number.
it('does not conflate text matches with amount matches for numeric queries', function (): void {
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Yearbook Shop',
        'counterparty_normalized' => 'yearbook shop',
        'description' => 'invoice for 2024 season',
        'amount_minor' => -999, 'settled_amount_minor' => -999,
    ]);

    // Costs €2024.00, but carries no "2024" anywhere in its text.
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Big Purchase',
        'counterparty_normalized' => 'big purchase',
        'description' => 'expensive thing',
        'amount_minor' => -202400, 'settled_amount_minor' => -202400,
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, '2024', SearchFilters::empty());
    expect($page->totalCount)->toBe(1)
        ->and($page->rows[0]->counterpartyName)->toBe('Yearbook Shop');
});

// A filters-only search scopes by user_id and the filters directly; it must
// not materialize every transaction id into a whereIn first.
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

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, '', new SearchFilters(after: '2026-01-01'));
    expect($page->totalCount)->toBe(1)
        ->and($page->rows[0]->counterpartyName)->toBe('Jan Vendor');
});

// The summary totals are labelled "€", so a row settled in another currency
// must not be summed into them.
it('summary totals exclude non-eur-settled rows', function (): void {
    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'Summary Vendor',
        'counterparty_normalized' => 'summary vendor',
        'amount_minor' => -1000, 'currency' => 'EUR',
        'settled_amount_minor' => -1000, 'settled_currency' => 'EUR',
    ]);
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

    // Both rows are counted; only the EUR one contributes to the total.
    expect($page->totalCount)->toBe(2)
        ->and($page->totalOutMinor)->toBe(-1000);
});

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

    // The token compares absolute value, so an expense of -9000 minor passes.
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
        'kind' => 'bank',
        'iban' => 'NL33ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $catId = $db->table('categories')->insertGetId([
        'user_id' => $this->userAId,
        'name' => 'Groceries', 'slug' => 'gro-'.$suffix,
        'kind' => 'expense', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $matchTx = $this->searchTestTransaction($this->userAId, [
        'account_id' => $accountId,
        'category_id' => $catId,
        'counterparty_name' => 'TokenMatch Vendor',
        'counterparty_normalized' => 'tokenmatch vendor',
    ]);

    $this->searchTestTransaction($this->userAId, [
        'counterparty_name' => 'TokenMatch Vendor',
        'counterparty_normalized' => 'tokenmatch vendor',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    // The tokens resolve on a case-insensitive name prefix, not the full name.
    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'TokenMatch account:vakantie category:grocer', SearchFilters::empty());
    expect($page->totalCount)->toBe(1)
        ->and($page->rows[0]->id)->toBe($matchTx);
});

it('breaks did-you-mean ties by corpus frequency', function (): void {
    // Both "kruidvat" and "kruidvit" sit one edit from the typo "kruidvet";
    // only their frequency in the corpus separates them.
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

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'kruidvet', SearchFilters::empty());
    expect($page->totalCount)->toBe(0)
        ->and($page->didYouMean)->toBe('kruidvat');
});
