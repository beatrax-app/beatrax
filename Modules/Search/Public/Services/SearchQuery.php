<?php

declare(strict_types=1);

namespace Modules\Search\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Search\Internal\Services\DidYouMeanSuggester;
use Modules\Search\Internal\Services\QueryParser;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Dto\SearchResultPage;
use Modules\Search\Public\Dto\SearchRowDto;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

// Full-text search read entry point for /transactions and the ⌘K
// palette: FTS5 MATCH + SQL filters + highlight + cursor pagination.
// Every FTS join and transactions read is scoped by user_id; filter
// IDs are ownership-validated before use.
/**
 * @link ../../../../.docs/features/search/architecture.md
 */
final class SearchQuery
{
    use CoercesScalars;

    // SQLite's highlight()/snippet() do not HTML-escape surrounding
    // text, so matches are marked with these control-char sentinels
    // (never present in transaction text) instead of literal <mark> —
    // decorateHighlight() escapes the string, then swaps them in.
    private const MARK_START = "\x02";

    private const MARK_END = "\x03";

    // Bounds the candidate window the <3-char LIKE fallback decrypts,
    // most-recent-first, so a short query never decrypts an entire
    // multi-year history on every keystroke.
    private const LIKE_FALLBACK_CANDIDATE_CAP = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly QueryParser $parser,
        private readonly DidYouMeanSuggester $suggester,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
        private readonly EncryptionMigrationService $encryptionService,
    ) {}

    public function search(
        User $user,
        string $q,
        SearchFilters $filters,
        ?int $cursorId = null,
        ?string $cursorPostedAt = null,
        int $limit = 50,
    ): SearchResultPage {
        $parsed = $this->parser->parse($q);
        $textQuery = $parsed['textQuery'];
        $parsedFilters = $parsed['filters'];

        $filters = $this->mergeTokenFilters($user, $filters, $parsedFilters);

        // null means "no text query" (filters-only mode) — the base
        // query is scoped by user_id + filters directly, with no
        // whereIn over a materialized id list (avoids the
        // SQLITE_MAX_VARIABLE_NUMBER crash on full history).
        $candidateIds = $this->resolveCandidateIds($user, $textQuery, $filters);

        // The amount-query branch only fires when the text branch
        // found no candidates — otherwise a bare numeric query like
        // "2024" would OR in every €2024.00 transaction, conflating
        // "text contains 2024" with "amount is €2024.00".
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

        $query = $this->buildBaseQuery($user, $candidateIds);

        $this->applyFilters($query, $user, $filters);

        // The strip totals are labelled "€", so the SUMs must only
        // aggregate rows whose settlement leg is actually EUR — a
        // non-EUR-settled or unsettled row would otherwise be summed
        // into a number shown under a € label.
        $summary = (clone $query)->selectRaw(
            "COUNT(*) as total_count,
             SUM(CASE WHEN settled_currency = 'EUR' AND settled_amount_minor < 0 THEN settled_amount_minor ELSE 0 END) as total_out,
             SUM(CASE WHEN settled_currency = 'EUR' AND settled_amount_minor > 0 THEN settled_amount_minor ELSE 0 END) as total_in",
        )->first();

        $totalCount = is_numeric($summary?->total_count) ? (int) $summary->total_count : 0;
        $totalOut = is_numeric($summary?->total_out) ? (int) $summary->total_out : 0;
        $totalIn = is_numeric($summary?->total_in) ? (int) $summary->total_in : 0;

        $query->limit($limit + 1);
        $this->applyCursor($query, $cursorPostedAt, $cursorId);

        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        $sliced = $rows->take($limit)->values();

        $highlights = [];
        if (strlen($textQuery) >= 3 && $candidateIds !== null && count($candidateIds) > 0) {
            $highlights = $this->loadHighlights($user, $textQuery, self::toIntList($sliced->pluck('id')->all()));
        }

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

    // Merges parsed token filters into the SearchFilters DTO; token
    // filters take precedence. The palette advertises
    // account:/category:/amount: tokens, so they must actually apply.
    /**
     * @param  array<string, mixed>  $parsedFilters
     */
    private function mergeTokenFilters(User $user, SearchFilters $filters, array $parsedFilters): SearchFilters
    {
        $accounts = $filters->accounts;
        if (isset($parsedFilters['accounts']) && is_array($parsedFilters['accounts'])) {
            $names = array_values(array_filter(
                $parsedFilters['accounts'],
                static fn (mixed $n): bool => is_string($n) && $n !== '',
            ));
            $resolved = $this->resolveNamesToIds($user, 'accounts', $names);
            $accounts = array_values(array_unique([...$accounts, ...$resolved]));
        }

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

        $amountMin = $filters->amountMin;
        $amountMax = $filters->amountMax;
        if (isset($parsedFilters['amount']) && is_string($parsedFilters['amount'])) {
            [$amountMin, $amountMax] = $this->parseAmountToken($parsedFilters['amount'], $amountMin, $amountMax);
        }

        return new SearchFilters(
            accounts: $accounts,
            categories: $categories,
            // No counterparty: query token exists yet — pass the
            // chip/URL value through unchanged so this wholesale
            // rebuild never silently drops it.
            counterparties: $filters->counterparties,
            after: $after,
            before: $before,
            amountMin: $amountMin,
            amountMax: $amountMax,
            amountDirection: $filters->amountDirection,
        );
    }

    // Case-insensitive prefix match on `name`, scoped to
    // accounts/categories (both expose a name column, keeping the raw
    // LIKE predicate a literal string). Categories also include
    // global (seeded, null-user) rows when $includeGlobal is true.
    /**
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

    // Parses an amount: token into [min, max] decimal strings: >50
    // (min), <50 (max), 50-100 (range), bare 50 (exact). Falls back
    // to the existing values on an unrecognized token.
    /**
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
            $n = $normalize($token);

            return [$n, $n];
        }

        return [$currentMin, $currentMax];
    }

    // Returns null for the empty-text (filters-only) branch to signal
    // "apply no id restriction"; otherwise a bounded list of matched
    // ids (possibly empty) via FTS5 MATCH or the LIKE fallback.
    /**
     * @return list<int>|null
     */
    private function resolveCandidateIds(User $user, string $textQuery, SearchFilters $filters): ?array
    {
        if ($textQuery === '') {
            return null;
        }

        if (strlen($textQuery) < 3) {
            return $this->likeFallbackIds($user, $textQuery, $filters);
        }

        // Short words (1-2 chars) still exist in the FTS body and
        // naturally pass through; they just can't be explicit MATCH
        // predicates, so they're filtered out of the MATCH expression.
        $allWords = array_values(array_filter(
            explode(' ', trim($textQuery)),
            static fn (string $w): bool => $w !== '',
        ));

        $ftsWords = array_values(array_filter(
            $allWords,
            static fn (string $w): bool => strlen($w) >= 3,
        ));

        if ($ftsWords === []) {
            return $this->likeFallbackIds($user, $textQuery, $filters);
        }

        // Each word is double-quote-wrapped with embedded quotes
        // doubled, the standard FTS5 MATCH escaping.
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

    // Fallback for queries too short for FTS5. Narrows the candidate
    // set in SQL on cheap plaintext dimensions only, then decrypts +
    // substring-matches in PHP (a no-op pass-through when disabled).
    /**
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
        $encryptionEnabled = $this->encryptionService->isEnabled($userId);

        $matched = [];
        foreach ($candidates as $row) {
            $storedName = $row->counterparty_name ?? null;
            $storedDescription = $row->description ?? null;

            $nameResult = is_string($storedName) && $storedName !== ''
                ? $this->codec->decryptValue('transactions', 'counterparty_name', $storedName, $userId, ($this->session)())
                : ['value' => '', 'decrypted' => true];
            $descriptionResult = is_string($storedDescription) && $storedDescription !== ''
                ? $this->codec->decryptValue('transactions', 'description', $storedDescription, $userId, ($this->session)())
                : ['value' => '', 'decrypted' => true];

            // A decrypted:false result under encryption is ciphertext
            // (rekey/epoch gap, or a locked app-lock) — skip the row
            // so the substring match never runs against ciphertext.
            if ($encryptionEnabled && (! $nameResult['decrypted'] || ! $descriptionResult['decrypted'])) {
                continue;
            }

            $counterpartyName = $nameResult['value'];
            $description = $descriptionResult['value'];

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

    private function applyFilters(Builder $query, User $user, SearchFilters $filters): void
    {
        if ($filters->after !== null) {
            $afterDate = strlen($filters->after) === 7 ? $filters->after.'-01' : $filters->after;
            $query->where('transactions.posted_at', '>=', $afterDate);
        }

        if ($filters->before !== null) {
            if (strlen($filters->before) === 7) {
                $beforeDate = CarbonImmutable::createFromFormat('Y-m', $filters->before)?->endOfMonth()->toDateString();
                if ($beforeDate !== null) {
                    $query->where('transactions.posted_at', '<=', $beforeDate);
                }
            } else {
                $query->where('transactions.posted_at', '<=', $filters->before);
            }
        }

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
                // Every supplied account id was foreign — return the
                // caller's own empty result, never another user's rows.
                $query->whereRaw('1 = 0');
            }
        }

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
                $query->whereRaw('1 = 0');
            }
        }

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
                $query->whereRaw('1 = 0');
            }
        }

        if ($filters->amountMin !== null) {
            $minMinor = (int) round((float) $filters->amountMin * 100);
            $query->whereRaw('ABS(transactions.settled_amount_minor) >= ?', [$minMinor]);
        }

        if ($filters->amountMax !== null) {
            $maxMinor = (int) round((float) $filters->amountMax * 100);
            $query->whereRaw('ABS(transactions.settled_amount_minor) <= ?', [$maxMinor]);
        }

        if ($filters->amountDirection === 'in') {
            $query->where('transactions.amount_minor', '>', 0);
        } elseif ($filters->amountDirection === 'out') {
            $query->where('transactions.amount_minor', '<', 0);
        }
    }

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

    // Escapes the raw text first, then swaps the control-char
    // sentinels for real <mark> tags — escaping before substitution
    // neutralises any HTML in the source text while the sentinels
    // survive (htmlspecialchars leaves STX/ETX untouched).
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
        // Read-side decrypt — transactions.counterparty_name is
        // ciphertext at rest once encryption is enabled; a
        // pass-through no-op otherwise.
        $counterpartyName = $row->counterparty_name === null
            ? null
            : $this->codec->decryptValue('transactions', 'counterparty_name', self::toString($row->counterparty_name), $userId, ($this->session)())['value'];

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

        // Both fields carry control-char match sentinels;
        // decorateHighlight() escapes the raw text and converts the
        // sentinels to <mark> tags so the output is XSS-safe.
        $highlightedCounterparty = null;
        $snippet = null;
        if ($highlight !== null) {
            $highlightedBody = self::toString($highlight->highlighted_body);
            $parts = explode(chr(12), $highlightedBody, 2);
            if ($parts[0] !== '' && str_contains($parts[0], self::MARK_START)) {
                $highlightedCounterparty = self::decorateHighlight($parts[0]);
            }
            // The snippet still carries the sentinels, so strip them
            // before comparing to the decrypted counterparty name —
            // otherwise the "don't repeat the counterparty" dedup
            // guard could never match once encryption is enabled.
            $snippetBody = self::toString($highlight->snippet_body);
            $snippetPlain = str_replace([self::MARK_START, self::MARK_END], '', $snippetBody);
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

    // Routes through brick/money (via the Ledger Money VO) so the
    // palette presents amounts exactly like the /transactions table
    // and never reintroduces float division for money.
    private function formatMinorAmount(int $minor, string $currency): string
    {
        return Money::ofMinor($minor, $currency)->format();
    }

    /**
     * @param  array<mixed>  $values
     * @return list<int>
     */
    private static function toIntList(array $values): array
    {
        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $values));
    }
}
