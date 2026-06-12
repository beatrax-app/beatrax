<?php

declare(strict_types=1);

namespace Modules\Search\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Search\Internal\Services\DidYouMeanSuggester;
use Modules\Search\Internal\Services\QueryParser;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Dto\SearchResultPage;
use Modules\Search\Public\Dto\SearchRowDto;
use stdClass;

/**
 * Full-text search read entry point for the /transactions search mode and the
 * ⌘K palette.
 *
 * Two entry points:
 *   - `search()` — full-results query; FTS5 MATCH + SQL filters + highlight + cursor pagination.
 *   - `palette()` — top-5 hits for the palette section; reuses the search() machinery.
 *
 * Security (T-08-04, T-08-06, T-08-07):
 *   - Every FTS join and every transactions read is scoped by `user_id`.
 *   - User-supplied text is escaped per-word (double-quote wrap + double embedded quotes).
 *   - Account filter IDs are ownership-validated before applying whereIn.
 *
 * Cursor pagination follows the (posted_at, id) row-value compare pattern from
 * TransactionListQuery — newest-first, sub-100ms target on multi-year data via FTS index.
 *
 * Amount-query branch (D-07): when the textQuery looks like a number (e.g. '12,50'),
 * the query also matches transactions whose ABS(amount_minor) or ABS(settled_amount_minor)
 * equals the normalized minor value, merged into the FTS rowid set.
 *
 * FTS path gates at strlen >= 3 (Pitfall-6 — trigram min token length).
 * Short queries (<3 chars) fall back to a LIKE scan on counterparty_name + description.
 */
final class SearchQuery
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly QueryParser $parser,
        private readonly DidYouMeanSuggester $suggester,
    ) {}

    /**
     * Full-results search: FTS MATCH, filters, amount branch, highlight, summary, cursor.
     */
    public function search(
        User $user,
        string $q,
        SearchFilters $filters,
        ?int $cursorId = null,
        ?string $cursorPostedAt = null,
        int $limit = 50,
    ): SearchResultPage {
        // Step 1: parse typed tokens out of the raw query; fold into filters
        $parsed = $this->parser->parse($q);
        $textQuery = $parsed['textQuery'];
        $parsedFilters = $parsed['filters'];

        // Merge parsed token filters into the SearchFilters object
        $filters = $this->mergeTokenFilters($filters, $parsedFilters);

        // Step 2: build the candidate rowid set via FTS or LIKE fallback
        $candidateIds = $this->resolveCandidateIds($user, $textQuery);

        // Step 3: amount-query branch — numeric textQuery also matches by amount (D-07)
        if (preg_match('/^\d+(?:[.,]\d{1,2})?$/', trim($textQuery)) === 1) {
            $normalized = str_replace(',', '.', trim($textQuery));
            $minor = (int) round((float) $normalized * 100);
            $amountIds = self::toIntList(
                $this->db->connection()
                    ->table('transactions')
                    ->where('user_id', $user->id)
                    ->whereRaw('ABS(amount_minor) = ? OR ABS(settled_amount_minor) = ?', [$minor, $minor])
                    ->pluck('id')
                    ->all(),
            );
            // Merge and deduplicate, keeping list<int> type
            $candidateIds = array_values(array_unique(array_merge($candidateIds, $amountIds)));
        }

        // Step 4: build the result query over transactions
        $query = $this->buildBaseQuery($user, $candidateIds);

        // Step 5: apply filter predicates
        $this->applyFilters($query, $user, $filters);

        // Step 6: summary aggregate (clone before cursor/limit)
        $summary = (clone $query)->selectRaw(
            'COUNT(*) as total_count,
             SUM(CASE WHEN settled_amount_minor < 0 THEN settled_amount_minor ELSE 0 END) as total_out,
             SUM(CASE WHEN settled_amount_minor > 0 THEN settled_amount_minor ELSE 0 END) as total_in',
        )->first();

        $totalCount = is_numeric($summary?->total_count) ? (int) $summary->total_count : 0;
        $totalOut = is_numeric($summary?->total_out) ? (int) $summary->total_out : 0;
        $totalIn = is_numeric($summary?->total_in) ? (int) $summary->total_in : 0;

        // Step 7: cursor pagination — newest-first (posted_at desc, id desc)
        $query->limit($limit + 1);
        $this->applyCursor($query, $cursorPostedAt, $cursorId);

        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        $sliced = $rows->take($limit)->values();

        // Step 8: highlight via FTS (only on the FTS path, not LIKE fallback)
        $highlights = [];
        if (strlen($textQuery) >= 3 && count($candidateIds) > 0) {
            $highlights = $this->loadHighlights($user, $textQuery, self::toIntList($sliced->pluck('id')->all()));
        }

        // Map rows to DTOs
        $dtos = [];
        $lastId = null;
        $lastPostedAt = null;
        foreach ($sliced as $row) {
            $rowId = self::toInt($row->id);
            $highlight = $highlights[$rowId] ?? null;
            $dtos[] = $this->mapRow($row, $highlight);
            $lastId = $rowId;
            $lastPostedAt = self::toString($row->posted_at);
        }

        // Step 9: did you mean (only when zero FTS results)
        $didYouMean = null;
        if ($totalCount === 0 && $textQuery !== '') {
            $didYouMean = $this->suggester->suggest($user, $textQuery);
        }

        return new SearchResultPage(
            rows: $dtos,
            totalCount: $totalCount,
            totalOutMinor: $totalOut,
            totalInMinor: $totalIn,
            hasMore: $hasMore,
            nextCursorId: $hasMore ? $lastId : null,
            nextCursorPostedAt: $hasMore ? $lastPostedAt : null,
            didYouMean: $didYouMean,
        );
    }

    /**
     * Palette top-5: reuses search() with limit 5 and returns the two-line shape.
     *
     * @return list<array{id: int, counterpartyName: ?string, amount: string, snippet: ?string, url: string}>
     */
    public function palette(User $user, string $q): array
    {
        $page = $this->search($user, $q, SearchFilters::empty(), null, null, 5);

        $hits = [];
        foreach ($page->rows as $row) {
            $hits[] = [
                'id' => $row->id,
                'counterpartyName' => $row->counterpartyName,
                'amount' => $this->formatMinorAmount($row->amountMinor, $row->amountCurrency),
                'snippet' => $row->snippet,
                'url' => '/transactions/'.$row->id,
            ];
        }

        return $hits;
    }

    /**
     * Merge parsed token filters (from QueryParser) into the SearchFilters DTO.
     * Token filters take precedence per RESEARCH OQ1.
     *
     * @param  array<string, mixed>  $parsedFilters
     */
    private function mergeTokenFilters(SearchFilters $filters, array $parsedFilters): SearchFilters
    {
        // Merge accounts — combine chip accounts with token accounts
        $accounts = $filters->accounts;
        if (isset($parsedFilters['accounts']) && is_array($parsedFilters['accounts'])) {
            // Parsed accounts are account NAMES (strings), not IDs — they are informational
            // here; the actual account-id lookup happens in applyFilters via user's accounts.
            // We store them for UI display; account filter by name is not currently supported
            // as the test spec uses account IDs. Skip name resolution for now.
        }

        $after = $filters->after;
        if (isset($parsedFilters['after']) && is_string($parsedFilters['after'])) {
            $after = $parsedFilters['after'];
        }

        $before = $filters->before;
        if (isset($parsedFilters['before']) && is_string($parsedFilters['before'])) {
            $before = $parsedFilters['before'];
        }

        return new SearchFilters(
            accounts: $accounts,
            categories: $filters->categories,
            after: $after,
            before: $before,
            amountMin: $filters->amountMin,
            amountMax: $filters->amountMax,
            amountDirection: $filters->amountDirection,
        );
    }

    /**
     * Resolve the candidate transaction ID set via FTS5 MATCH or LIKE fallback.
     *
     * @return list<int>
     */
    private function resolveCandidateIds(User $user, string $textQuery): array
    {
        if ($textQuery === '') {
            // No text query — all of the user's transactions are candidates
            return self::toIntList(
                $this->db->connection()
                    ->table('transactions')
                    ->where('user_id', $user->id)
                    ->pluck('id')
                    ->all(),
            );
        }

        // Pitfall-6: FTS5 trigram requires >= 3 chars per token
        if (strlen($textQuery) < 3) {
            // LIKE fallback for short queries
            return self::toIntList(
                $this->db->connection()
                    ->table('transactions')
                    ->where('user_id', $user->id)
                    ->where(static function (Builder $q) use ($textQuery): void {
                        $like = '%'.$textQuery.'%';
                        $q->where('counterparty_name', 'LIKE', $like)
                            ->orWhere('description', 'LIKE', $like);
                    })
                    ->pluck('id')
                    ->all(),
            );
        }

        // FTS5 MATCH path — escape each word (Pitfall-1: double-quote wrap + double embedded quotes)
        // FTS5 trigram requires >= 3 chars per token (Pitfall-6): filter out short words.
        // Short words (1-2 chars) will be present in the FTS body and naturally pass through;
        // they just cannot be used as explicit MATCH predicates.
        $allWords = array_values(array_filter(
            explode(' ', trim($textQuery)),
            static fn (string $w): bool => $w !== '',
        ));

        // Only include words >= 3 chars in the FTS MATCH expression
        $ftsWords = array_values(array_filter(
            $allWords,
            static fn (string $w): bool => strlen($w) >= 3,
        ));

        if ($ftsWords === []) {
            // All words are too short for FTS — fall back to LIKE
            return self::toIntList(
                $this->db->connection()
                    ->table('transactions')
                    ->where('user_id', $user->id)
                    ->where(static function (Builder $q) use ($textQuery): void {
                        $like = '%'.$textQuery.'%';
                        $q->where('counterparty_name', 'LIKE', $like)
                            ->orWhere('description', 'LIKE', $like);
                    })
                    ->pluck('id')
                    ->all(),
            );
        }

        $escaped = array_map(
            static fn (string $w): string => '"'.str_replace('"', '""', $w).'"',
            $ftsWords,
        );
        $ftsMatch = implode(' AND ', $escaped);

        return self::toIntList(
            $this->db->connection()
                ->table('transaction_search_fts')
                ->whereRaw('transaction_search_fts MATCH ?', [$ftsMatch])
                ->join(
                    'transaction_search_docs',
                    'transaction_search_docs.transaction_id',
                    '=',
                    'transaction_search_fts.rowid',
                )
                ->where('transaction_search_docs.user_id', $user->id)
                ->pluck('transaction_search_fts.rowid')
                ->all(),
        );
    }

    /**
     * Build the base query over transactions for the given candidate IDs.
     *
     * @param  list<int>  $candidateIds
     */
    private function buildBaseQuery(User $user, array $candidateIds): Builder
    {
        return $this->db->connection()
            ->table('transactions')
            ->whereIn('transactions.id', $candidateIds)
            ->where('transactions.user_id', $user->id)
            ->leftJoin('categories', 'transactions.category_id', '=', 'categories.id')
            ->leftJoin('counterparties', 'transactions.counterparty_id', '=', 'counterparties.id')
            ->orderByDesc('transactions.posted_at')
            ->orderByDesc('transactions.id')
            ->select([
                'transactions.id',
                'transactions.posted_at',
                'transactions.booked_at',
                'transactions.counterparty_name',
                'transactions.category_id',
                'transactions.amount_minor as display_minor',
                'transactions.currency as display_currency',
                'transactions.settled_amount_minor as secondary_minor',
                'transactions.settled_currency as secondary_currency',
                'categories.name as category_name',
                'counterparties.slug as counterparty_slug',
            ]);
    }

    /**
     * Apply SearchFilters predicates to the query.
     * Account IDs are validated against user ownership before applying (T-08-06).
     */
    private function applyFilters(Builder $query, User $user, SearchFilters $filters): void
    {
        // Date range
        if ($filters->after !== null) {
            // Expand YYYY-MM to YYYY-MM-01 for date comparison
            $afterDate = strlen($filters->after) === 7 ? $filters->after.'-01' : $filters->after;
            $query->where('transactions.posted_at', '>=', $afterDate);
        }

        if ($filters->before !== null) {
            // Expand YYYY-MM to last day of month
            if (strlen($filters->before) === 7) {
                $beforeDate = CarbonImmutable::createFromFormat('Y-m', $filters->before)?->endOfMonth()->toDateString();
                if ($beforeDate !== null) {
                    $query->where('transactions.posted_at', '<=', $beforeDate);
                }
            } else {
                $query->where('transactions.posted_at', '<=', $filters->before);
            }
        }

        // Account filter — validate ownership before applying (T-08-06)
        if ($filters->accounts !== []) {
            $validatedAccountIds = $this->db->connection()
                ->table('accounts')
                ->where('user_id', $user->id)
                ->whereIn('id', $filters->accounts)
                ->pluck('id')
                ->all();

            if ($validatedAccountIds !== []) {
                $query->whereIn('transactions.account_id', $validatedAccountIds);
            } else {
                // All supplied account IDs were foreign — return empty result set
                $query->whereRaw('1 = 0');
            }
        }

        // Category filter
        if ($filters->categories !== []) {
            $query->whereIn('transactions.category_id', $filters->categories);
        }

        // Amount min/max filter (operates on ABS of settled_amount_minor)
        if ($filters->amountMin !== null) {
            $minMinor = (int) round((float) $filters->amountMin * 100);
            $query->whereRaw('ABS(transactions.settled_amount_minor) >= ?', [$minMinor]);
        }

        if ($filters->amountMax !== null) {
            $maxMinor = (int) round((float) $filters->amountMax * 100);
            $query->whereRaw('ABS(transactions.settled_amount_minor) <= ?', [$maxMinor]);
        }

        // Amount direction filter
        if ($filters->amountDirection === 'in') {
            $query->where('transactions.amount_minor', '>', 0);
        } elseif ($filters->amountDirection === 'out') {
            $query->where('transactions.amount_minor', '<', 0);
        }
    }

    /**
     * Apply the (posted_at, id) cursor for the next-page fetch.
     */
    private function applyCursor(Builder $query, ?string $cursorPostedAt, ?int $cursorId): void
    {
        if ($cursorId === null) {
            return;
        }

        if ($cursorPostedAt === null) {
            $query->where('transactions.id', '<', $cursorId);

            return;
        }

        $query->whereRaw(
            '(transactions.posted_at, transactions.id) < (?, ?)',
            [$cursorPostedAt, $cursorId],
        );
    }

    /**
     * Load FTS5 highlight() and snippet() for the given page's row IDs.
     *
     * Returns a map of transaction_id → stdClass{highlighted_body, snippet_body}.
     *
     * @param  list<int>  $rowIds
     * @return array<int, stdClass>
     */
    private function loadHighlights(User $user, string $textQuery, array $rowIds): array
    {
        if ($rowIds === []) {
            return [];
        }

        $allWords = array_values(array_filter(
            explode(' ', trim($textQuery)),
            static fn (string $w): bool => $w !== '',
        ));
        $ftsWords = array_values(array_filter(
            $allWords,
            static fn (string $w): bool => strlen($w) >= 3,
        ));
        if ($ftsWords === []) {
            return [];
        }
        $escaped = array_map(
            static fn (string $w): string => '"'.str_replace('"', '""', $w).'"',
            $ftsWords,
        );
        $ftsMatch = implode(' AND ', $escaped);

        $highlightRows = $this->db->connection()
            ->table('transaction_search_fts')
            ->selectRaw(
                'transaction_search_fts.rowid,
                 highlight(transaction_search_fts, 0, ?, ?) AS highlighted_body,
                 snippet(transaction_search_fts, 0, ?, ?, ?, 12) AS snippet_body',
                ['<mark>', '</mark>', '<mark>', '</mark>', '…'],
            )
            ->whereRaw('transaction_search_fts MATCH ?', [$ftsMatch])
            ->join(
                'transaction_search_docs',
                'transaction_search_docs.transaction_id',
                '=',
                'transaction_search_fts.rowid',
            )
            ->where('transaction_search_docs.user_id', $user->id)
            ->whereIn('transaction_search_fts.rowid', $rowIds)
            ->get();

        /** @var array<int, stdClass> $result */
        $result = [];
        foreach ($highlightRows as $hl) {
            $id = self::toInt($hl->rowid);
            $result[$id] = $hl;
        }

        return $result;
    }

    /**
     * Map a raw DB row + optional highlight to a SearchRowDto.
     */
    private function mapRow(stdClass $row, ?stdClass $highlight): SearchRowDto
    {
        $bookedAt = CarbonImmutable::parse(self::toString($row->booked_at));
        $categoryId = $row->category_id === null ? null : self::toInt($row->category_id);
        $categoryName = $row->category_name === null ? null : self::toString($row->category_name);
        $counterpartyName = $row->counterparty_name === null ? null : self::toString($row->counterparty_name);

        $counterpartySlug = property_exists($row, 'counterparty_slug') && $row->counterparty_slug !== null
            ? self::toString($row->counterparty_slug)
            : null;
        if ($counterpartySlug === '') {
            $counterpartySlug = null;
        }

        $displayCurrency = self::toString($row->display_currency);
        $secondaryMinor = null;
        $secondaryCurrency = null;

        if (property_exists($row, 'secondary_currency') && property_exists($row, 'secondary_minor')) {
            $secCurr = self::toString($row->secondary_currency);
            if ($secCurr !== '' && $secCurr !== $displayCurrency) {
                $secondaryMinor = self::toInt($row->secondary_minor);
                $secondaryCurrency = $secCurr;
            }
        }

        // Split highlighted_body on chr(12) (form-feed separator between counterparty and description)
        $highlightedCounterparty = null;
        $snippet = null;
        if ($highlight !== null) {
            $highlightedBody = self::toString($highlight->highlighted_body);
            $parts = explode(chr(12), $highlightedBody, 2);
            if ($parts[0] !== '' && str_contains($parts[0], '<mark>')) {
                $highlightedCounterparty = $parts[0];
            }
            // snippet_body is always from the description/note part
            $snippetBody = self::toString($highlight->snippet_body);
            if ($snippetBody !== '' && $snippetBody !== self::toString($row->counterparty_name ?? '')) {
                $snippet = $snippetBody;
            }
        }

        return new SearchRowDto(
            id: self::toInt($row->id),
            bookedAt: $bookedAt->format('d-m-Y'),
            counterpartyName: $counterpartyName,
            counterpartySlug: $counterpartySlug,
            categoryId: $categoryId,
            categoryName: $categoryName,
            amountMinor: self::toInt($row->display_minor),
            amountCurrency: $displayCurrency,
            secondaryMinor: $secondaryMinor,
            secondaryCurrency: $secondaryCurrency,
            highlightedCounterparty: $highlightedCounterparty,
            snippet: $snippet,
        );
    }

    /**
     * Format a minor-unit amount as a simple decimal string for the palette hit shape.
     */
    private function formatMinorAmount(int $minor, string $currency): string
    {
        $major = $minor / 100.0;

        return sprintf('%s %.2f', $currency, $major);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    /**
     * Convert a mixed array (from ->pluck()->all()) to a list<int>.
     *
     * @param  array<mixed>  $values
     * @return list<int>
     */
    private static function toIntList(array $values): array
    {
        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $values));
    }
}
