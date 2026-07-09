<?php

declare(strict_types=1);

namespace Modules\Search\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Search\Internal\Services\DidYouMeanSuggester;
use Modules\Search\Internal\Services\QueryParser;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Dto\SearchResultPage;
use Modules\Search\Public\Dto\SearchRowDto;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
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
 *   - Account/category/counterparty filter IDs are ownership-validated before
 *     applying whereIn (WR-04: categories additionally honor global,
 *     null-user rows).
 *
 * Cursor pagination follows the (posted_at, id) row-value compare pattern from
 * TransactionListQuery — newest-first, sub-100ms target on multi-year data via FTS index.
 *
 * Amount-query branch (D-07): when the textQuery looks like a number (e.g. '12,50'),
 * the query also matches transactions whose ABS(amount_minor) or ABS(settled_amount_minor)
 * equals the normalized minor value, merged into the FTS rowid set.
 *
 * FTS path gates at strlen >= 3 (Pitfall-6 — trigram min token length).
 * Short queries (<3 chars) fall back to a bounded decrypt-then-substring
 * scan on counterparty_name + description (CRYPT-01/D-06 — these columns
 * are ciphertext at rest once encryption is enabled, so the fallback can no
 * longer predicate in SQL; see likeFallbackIds()).
 */
final class SearchQuery
{
    /**
     * Sentinel markers FTS5 highlight()/snippet() wrap around matched tokens.
     * SQLite's highlight()/snippet() do NOT HTML-escape the surrounding text,
     * so emitting literal `<mark>` and rendering raw in Blade is a stored-XSS
     * vector (a counterparty named `<img onerror=…>` would execute). Instead we
     * mark matches with control-char sentinels (STX/ETX — never present in
     * transaction text and untouched by htmlspecialchars), then in
     * decorateHighlight() escape the whole string and swap the sentinels for
     * real `<mark>` tags. The result is safe to render with `{!! !!}`.
     */
    private const MARK_START = "\x02";

    private const MARK_END = "\x03";

    /**
     * CRYPT-01 (D-06) / RESEARCH Pitfall 3: the <3-char LIKE fallback can no
     * longer run a SQL LIKE against ciphertext — it must decrypt candidate
     * rows in PHP. Bound the candidate window so a short query never
     * decrypts an entire multi-year transaction history on every keystroke.
     * Most-recent-first ordering keeps the bound useful in practice.
     */
    private const LIKE_FALLBACK_CANDIDATE_CAP = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly QueryParser $parser,
        private readonly DidYouMeanSuggester $suggester,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
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
        $filters = $this->mergeTokenFilters($user, $filters, $parsedFilters);

        // Step 2: build the candidate rowid set via FTS or LIKE fallback.
        // A null result means "no text query" (filters-only mode) — the base
        // query is scoped by user_id + filters directly, with no whereIn over a
        // materialized id list (WR-03: avoids the SQLITE_MAX_VARIABLE_NUMBER
        // crash on full history).
        $candidateIds = $this->resolveCandidateIds($user, $textQuery, $filters);

        // Step 3: amount-query branch — numeric textQuery ALSO matches by amount
        // (D-07), but only when the text branch found no candidates. Otherwise a
        // bare numeric query like "2024" would OR in every €2024.00 transaction
        // and conflate "text contains 2024" with "amount is €2024.00" (WR-02).
        if (
            $candidateIds !== null
            && $candidateIds === []
            && preg_match('/^\d+(?:[.,]\d{1,2})?$/', trim($textQuery)) === 1
        ) {
            $normalized = str_replace(',', '.', trim($textQuery));
            $minor = (int) round((float) $normalized * 100);
            $candidateIds = self::toIntList(
                $this->db->connection()
                    ->table('transactions')
                    ->where('user_id', $user->id)
                    ->whereRaw('ABS(amount_minor) = ? OR ABS(settled_amount_minor) = ?', [$minor, $minor])
                    ->pluck('id')
                    ->all(),
            );
        }

        // Step 4: build the result query over transactions
        $query = $this->buildBaseQuery($user, $candidateIds);

        // Step 5: apply filter predicates
        $this->applyFilters($query, $user, $filters);

        // Step 6: summary aggregate (clone before cursor/limit).
        // WR-01: the strip totals are labelled "€", so the SUMs must only
        // aggregate rows whose settlement leg is actually EUR. settled_amount_minor
        // is the settled-EUR leg for FX rows (usually EUR) but a non-EUR-settled
        // or unsettled (null) row would otherwise be summed into a number shown
        // under a € label — a wrong total for a finance app. total_count stays the
        // full match count; only the money totals are currency-constrained.
        $summary = (clone $query)->selectRaw(
            "COUNT(*) as total_count,
             SUM(CASE WHEN settled_currency = 'EUR' AND settled_amount_minor < 0 THEN settled_amount_minor ELSE 0 END) as total_out,
             SUM(CASE WHEN settled_currency = 'EUR' AND settled_amount_minor > 0 THEN settled_amount_minor ELSE 0 END) as total_in",
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
        if (strlen($textQuery) >= 3 && $candidateIds !== null && count($candidateIds) > 0) {
            $highlights = $this->loadHighlights($user, $textQuery, self::toIntList($sliced->pluck('id')->all()));
        }

        // Map rows to DTOs
        $dtos = [];
        $lastId = null;
        $lastPostedAt = null;
        foreach ($sliced as $row) {
            $rowId = self::toInt($row->id);
            $highlight = $highlights[$rowId] ?? null;
            $dtos[] = $this->mapRow($row, $highlight, $user->id);
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
     * WR-08: the palette advertises `account:`, `category:` and `amount:` tokens,
     * so they must actually apply. `account:`/`category:` names are resolved to
     * IDs scoped to the user (case-insensitive prefix match); `amount:` is wired
     * into amountMin/amountMax/direction. Chip filter IDs and token-resolved IDs
     * are merged.
     *
     * @param  array<string, mixed>  $parsedFilters
     */
    private function mergeTokenFilters(User $user, SearchFilters $filters, array $parsedFilters): SearchFilters
    {
        // Merge accounts — combine chip account IDs with token-resolved IDs.
        $accounts = $filters->accounts;
        if (isset($parsedFilters['accounts']) && is_array($parsedFilters['accounts'])) {
            $names = array_values(array_filter(
                $parsedFilters['accounts'],
                static fn (mixed $n): bool => is_string($n) && $n !== '',
            ));
            $resolved = $this->resolveNamesToIds($user, 'accounts', $names);
            $accounts = array_values(array_unique([...$accounts, ...$resolved]));
        }

        // Merge categories — chip IDs + token-resolved IDs (case-insensitive).
        $categories = $filters->categories;
        if (isset($parsedFilters['category']) && is_string($parsedFilters['category']) && $parsedFilters['category'] !== '') {
            $resolved = $this->resolveNamesToIds($user, 'categories', [$parsedFilters['category']], includeGlobal: true);
            $categories = array_values(array_unique([...$categories, ...$resolved]));
        }

        $after = $filters->after;
        if (isset($parsedFilters['after']) && is_string($parsedFilters['after'])) {
            $after = $parsedFilters['after'];
        }

        $before = $filters->before;
        if (isset($parsedFilters['before']) && is_string($parsedFilters['before'])) {
            $before = $parsedFilters['before'];
        }

        // Wire amount: token into min/max/direction.
        $amountMin = $filters->amountMin;
        $amountMax = $filters->amountMax;
        if (isset($parsedFilters['amount']) && is_string($parsedFilters['amount'])) {
            [$amountMin, $amountMax] = $this->parseAmountToken($parsedFilters['amount'], $amountMin, $amountMax);
        }

        return new SearchFilters(
            accounts: $accounts,
            categories: $categories,
            // No `counterparty:` query token exists yet — pass the chip/URL
            // value straight through so it survives the token-merge rebuild
            // (this rebuild replaces $filters wholesale; omitting a field
            // here would silently drop it, Rule 1).
            counterparties: $filters->counterparties,
            after: $after,
            before: $before,
            amountMin: $amountMin,
            amountMax: $amountMax,
            amountDirection: $filters->amountDirection,
        );
    }

    /**
     * Resolve a list of names to row IDs on a user-scoped table via a
     * case-insensitive prefix match on the `name` column (WR-08). Categories
     * also include global (seeded, null-user) rows when $includeGlobal is true.
     *
     * Restricted to the `accounts` and `categories` tables — both expose a
     * `name` column — so the raw LIKE predicate stays a literal string.
     *
     * @param  'accounts'|'categories'  $table
     * @param  list<string>  $names
     * @return list<int>
     */
    private function resolveNamesToIds(User $user, string $table, array $names, bool $includeGlobal = false): array
    {
        if ($names === []) {
            return [];
        }

        $query = $this->db->connection()
            ->table($table)
            ->where(function (Builder $scope) use ($user, $includeGlobal): void {
                $scope->where('user_id', $user->id);
                if ($includeGlobal) {
                    $scope->orWhereNull('user_id');
                }
            })
            ->where(function (Builder $match) use ($names): void {
                foreach ($names as $name) {
                    $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $name).'%';
                    $match->orWhereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '\\'", [$like]);
                }
            });

        return self::toIntList($query->pluck('id')->all());
    }

    /**
     * Parse an `amount:` token value into [min, max] decimal strings.
     *
     * Supports `>50` (min), `<50` (max), `50-100` (range), bare `50` (exact →
     * min == max). Falls back to the existing values on an unrecognized token.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function parseAmountToken(string $token, ?string $currentMin, ?string $currentMax): array
    {
        $token = trim($token);

        if ($token === '') {
            return [$currentMin, $currentMax];
        }

        $normalize = static fn (string $v): string => str_replace(',', '.', $v);

        if (str_starts_with($token, '>')) {
            return [$normalize(substr($token, 1)), $currentMax];
        }

        if (str_starts_with($token, '<')) {
            return [$currentMin, $normalize(substr($token, 1))];
        }

        if (preg_match('/^(\d+(?:[.,]\d{1,2})?)-(\d+(?:[.,]\d{1,2})?)$/', $token, $m) === 1) {
            return [$normalize($m[1]), $normalize($m[2])];
        }

        if (preg_match('/^\d+(?:[.,]\d{1,2})?$/', $token) === 1) {
            // Bare number → exact amount (min == max).
            $n = $normalize($token);

            return [$n, $n];
        }

        return [$currentMin, $currentMax];
    }

    /**
     * Resolve the candidate transaction ID set via FTS5 MATCH or LIKE fallback.
     *
     * Returns null for the empty-text (filters-only) branch to signal that no
     * whereIn candidate restriction should be applied (WR-03); otherwise a
     * bounded list of matched transaction ids (possibly empty).
     *
     * @return list<int>|null
     */
    private function resolveCandidateIds(User $user, string $textQuery, SearchFilters $filters): ?array
    {
        if ($textQuery === '') {
            // No text query (filters-only mode). Return null to signal "do not
            // restrict by a candidate id list" — buildBaseQuery scopes by
            // user_id and applyFilters() narrows from there. Materializing every
            // transaction id into a whereIn would blow SQLITE_MAX_VARIABLE_NUMBER
            // on full history (WR-03).
            return null;
        }

        // Pitfall-6: FTS5 trigram requires >= 3 chars per token
        if (strlen($textQuery) < 3) {
            // LIKE fallback for short queries
            return $this->likeFallbackIds($user, $textQuery, $filters);
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
            return $this->likeFallbackIds($user, $textQuery, $filters);
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
     * LIKE fallback used when the query is too short for the FTS5 trigram path.
     *
     * CRYPT-01 (D-06): `counterparty_name`/`description` are ciphertext at
     * rest once encryption is enabled — a raw `whereRaw(...LIKE...)` on the
     * stored column can never match plaintext user input. Converted to the
     * decrypt-then-match template (`CounterpartyIndexQuery::forUser()`
     * precedent, RESEARCH "Decrypt-then-Match Template"): narrow the
     * candidate set in SQL on cheap plaintext dimensions ONLY — user_id plus
     * whatever date/account/category/counterparty/amount filters are already
     * active (reusing `applyFilters()` is safe here since every predicate it
     * applies targets non-sensitive columns) — then decrypt and
     * substring-match in PHP. `decryptValue()` is a documented pass-through
     * no-op when encryption is not enabled for the user, so this path is
     * behavior-identical to the old LIKE for a plaintext user.
     *
     * Bounded scan (RESEARCH Pitfall 3): even with zero active filters, cap
     * the candidate window at LIKE_FALLBACK_CANDIDATE_CAP rows,
     * most-recent-first, so a <3-char query never decrypts an entire
     * multi-year transaction history on every keystroke.
     *
     * @return list<int>
     */
    private function likeFallbackIds(User $user, string $textQuery, SearchFilters $filters): array
    {
        $query = $this->db->connection()
            ->table('transactions')
            ->where('transactions.user_id', $user->id);

        $this->applyFilters($query, $user, $filters);

        /** @var iterable<stdClass> $candidates */
        $candidates = $query
            ->orderByDesc('transactions.posted_at')
            ->orderByDesc('transactions.id')
            ->limit(self::LIKE_FALLBACK_CANDIDATE_CAP)
            ->get(['transactions.id', 'transactions.counterparty_name', 'transactions.description']);

        $needle = mb_strtolower($textQuery);
        $userId = $user->id;

        $matched = [];
        foreach ($candidates as $row) {
            $storedName = $row->counterparty_name ?? null;
            $storedDescription = $row->description ?? null;

            $counterpartyName = is_string($storedName) && $storedName !== ''
                ? $this->codec->decryptValue('transactions', 'counterparty_name', $storedName, $userId, $this->session)['value']
                : '';
            $description = is_string($storedDescription) && $storedDescription !== ''
                ? $this->codec->decryptValue('transactions', 'description', $storedDescription, $userId, $this->session)['value']
                : '';

            if (
                ($counterpartyName !== '' && str_contains(mb_strtolower($counterpartyName), $needle))
                || ($description !== '' && str_contains(mb_strtolower($description), $needle))
            ) {
                $matched[] = self::toInt($row->id);
            }
        }

        return $matched;
    }

    /**
     * Build the base query over transactions for the given candidate IDs.
     *
     * When $candidateIds is null (filters-only mode), no id restriction is
     * applied — the user_id predicate + applyFilters() scope the result set
     * directly (WR-03). An empty list restricts to nothing (genuine zero-hit).
     *
     * @param  list<int>|null  $candidateIds
     */
    private function buildBaseQuery(User $user, ?array $candidateIds): Builder
    {
        $query = $this->db->connection()
            ->table('transactions')
            ->where('transactions.user_id', $user->id);

        if ($candidateIds !== null) {
            $query->whereIn('transactions.id', $candidateIds);
        }

        return $query
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

        // Category filter — validate ownership before applying (WR-04, mirrors
        // the account/counterparty blocks above). Categories may be global
        // (seeded, null-user) rows, so ownership is "mine OR global", not a
        // strict user_id match — same rule `resolveNamesToIds()` already
        // applies for `category:` token resolution. A foreign (non-owned,
        // non-global) id yields the caller's own empty result, never another
        // user's rows.
        if ($filters->categories !== []) {
            $validatedCategoryIds = $this->db->connection()
                ->table('categories')
                ->where(function (Builder $scope) use ($user): void {
                    $scope->where('user_id', $user->id)->orWhereNull('user_id');
                })
                ->whereIn('id', $filters->categories)
                ->pluck('id')
                ->all();

            if ($validatedCategoryIds !== []) {
                $query->whereIn('transactions.category_id', $validatedCategoryIds);
            } else {
                // All supplied category IDs were foreign — return empty result set
                $query->whereRaw('1 = 0');
            }
        }

        // Counterparty filter — validate ownership before applying (mirrors
        // the account block above, T-999.6-04 / Pitfall 4). A foreign id
        // yields the caller's own empty result, never another user's rows.
        if ($filters->counterparties !== []) {
            $validatedCounterpartyIds = $this->db->connection()
                ->table('counterparties')
                ->where('user_id', $user->id)
                ->whereIn('id', $filters->counterparties)
                ->pluck('id')
                ->all();

            if ($validatedCounterpartyIds !== []) {
                $query->whereIn('transactions.counterparty_id', $validatedCounterpartyIds);
            } else {
                // All supplied counterparty IDs were foreign — return empty result set
                $query->whereRaw('1 = 0');
            }
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
                [self::MARK_START, self::MARK_END, self::MARK_START, self::MARK_END, '…'],
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
    /**
     * Convert a sentinel-marked FTS fragment into XSS-safe highlight markup:
     * HTML-escape the raw (user-controlled) text first, then swap the control-char
     * sentinels for real <mark> tags. Escaping before substitution guarantees any
     * HTML in counterparty/description/note text is neutralised while the marks
     * survive (htmlspecialchars leaves STX/ETX untouched). Output is safe for {!! !!}.
     */
    private static function decorateHighlight(string $marked): string
    {
        $escaped = htmlspecialchars($marked, ENT_QUOTES, 'UTF-8');

        return str_replace(
            [self::MARK_START, self::MARK_END],
            ['<mark>', '</mark>'],
            $escaped,
        );
    }

    private function mapRow(stdClass $row, ?stdClass $highlight, int $userId): SearchRowDto
    {
        $bookedAt = CarbonImmutable::parse(self::toString($row->booked_at));
        $categoryId = $row->category_id === null ? null : self::toInt($row->category_id);
        $categoryName = $row->category_name === null ? null : self::toString($row->category_name);
        // CRYPT-01 (D-02b) read-side decrypt — transactions.counterparty_name
        // is ciphertext at rest once encryption is enabled; pass-through
        // no-op otherwise. The FTS-sourced highlight/snippet below are
        // already plaintext (D-02c disclosed shadow) and need no decrypt.
        $counterpartyName = $row->counterparty_name === null
            ? null
            : $this->codec->decryptValue('transactions', 'counterparty_name', self::toString($row->counterparty_name), $userId, $this->session)['value'];

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

        // Split highlighted_body on chr(12) (form-feed separator between counterparty and description).
        // Both fields carry control-char match sentinels (see MARK_START/MARK_END); decorateHighlight()
        // HTML-escapes the raw text and converts the sentinels to <mark> tags so the output is XSS-safe.
        $highlightedCounterparty = null;
        $snippet = null;
        if ($highlight !== null) {
            $highlightedBody = self::toString($highlight->highlighted_body);
            $parts = explode(chr(12), $highlightedBody, 2);
            if ($parts[0] !== '' && str_contains($parts[0], self::MARK_START)) {
                $highlightedCounterparty = self::decorateHighlight($parts[0]);
            }
            // snippet_body is always from the description/note part.
            // WR-07: the snippet still carries the MARK_START/MARK_END sentinels,
            // so strip them before comparing to the raw counterparty name —
            // otherwise the "don't repeat the counterparty" guard never matches
            // and a snippet that merely duplicates the name still renders.
            $snippetBody = self::toString($highlight->snippet_body);
            $snippetPlain = str_replace([self::MARK_START, self::MARK_END], '', $snippetBody);
            // Compare against the DECRYPTED counterparty name (not the raw,
            // possibly-ciphertext $row->counterparty_name) — otherwise this
            // dedup guard can never match once encryption is enabled.
            if ($snippetBody !== '' && $snippetPlain !== ($counterpartyName ?? '')) {
                $snippet = self::decorateHighlight($snippetBody);
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
     * Format a minor-unit amount for the palette hit shape.
     *
     * IN-02: route through brick/money (via the Ledger Money VO) so the palette
     * presents amounts exactly like the /transactions table (`€ 12,50` /
     * `$74.43`) and never reintroduces float division for money (project rule).
     * Money::format() auto-selects the locale from the currency code.
     */
    private function formatMinorAmount(int $minor, string $currency): string
    {
        return Money::ofMinor($minor, $currency)->format();
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
