<?php

declare(strict_types=1);

namespace Modules\Search\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Services\TransactionCursor;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Search\Internal\Services\DidYouMeanSuggester;
use Modules\Search\Internal\Services\FtsCandidateResolver;
use Modules\Search\Internal\Services\QueryParser;
use Modules\Search\Internal\Services\SearchRowMapper;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Dto\SearchResultPage;

// Full-text search read entry point for /transactions and the ⌘K
// palette: FTS5 MATCH + SQL filters + highlight + cursor pagination.
// Every FTS join and transactions read is scoped by user_id; filter
// IDs are ownership-validated before use.
final class SearchQuery
{
    use CoercesScalars;

    private const MATCH_NOTHING = '1 = 0';

    // An id no row can hold, standing in for "this token matched nothing".
    // Dropping an unresolvable filter instead returned the WHOLE history,
    // which reads to the typist exactly as if the token had worked.
    private const NO_SUCH_ID = 0;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly QueryParser $parser,
        private readonly DidYouMeanSuggester $suggester,
        private readonly FtsCandidateResolver $ftsResolver,
        private readonly SearchRowMapper $rowMapper,
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

        // null means "no text query" (filters-only mode) — the base query
        // is scoped by user_id + filters directly, with no whereIn over a
        // materialized id list (avoids the SQLITE_MAX_VARIABLE_NUMBER
        // crash on full history); the fallback scan reuses applyFilters.
        $candidateIds = $this->ftsResolver->resolve(
            $user,
            $textQuery,
            function (Builder $candidateQuery) use ($user, $filters): void {
                $this->applyFilters($candidateQuery, $user, $filters);
            },
        );

        // The amount-query branch only fires when the text branch
        // found no candidates — otherwise a bare numeric query like
        // "2024" would OR in every €2024.00 transaction, conflating
        // "text contains 2024" with "amount is €2024.00".
        if (
            $candidateIds !== null
            && $candidateIds === []
            && preg_match('/^\d+(?:[.,]\d{1,2})?$/', trim($textQuery)) === 1
        ) {
            $minor = MoneyInput::tryToMinor($textQuery) ?? 0;
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
        TransactionCursor::apply($query, $cursorPostedAt, $cursorId);

        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        $sliced = $rows->take($limit)->values();

        $highlights = [];
        if (strlen($textQuery) >= 3 && $candidateIds !== null && count($candidateIds) > 0) {
            $highlights = $this->ftsResolver->loadHighlights($user, $textQuery, self::toIntList($sliced->pluck('id')->all()));
        }

        $dtos = [];
        $lastId = null;
        $lastPostedAt = null;
        foreach ($sliced as $row) {
            $rowId = self::toInt($row->id);
            $highlight = $highlights[$rowId] ?? null;
            $dtos[] = $this->rowMapper->map($row, $highlight, $user->id);
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
                'amount' => $this->rowMapper->formatMinorAmount($row->amountMinor, $row->amountCurrency),
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
            if ($names !== []) {
                $resolved = self::orNoMatch($this->resolveAccountNamesToIds($user, $names));
                $accounts = array_values(array_unique([...$accounts, ...$resolved]));
            }
        }

        $categories = $filters->categories;
        if (isset($parsedFilters['category']) && is_string($parsedFilters['category']) && $parsedFilters['category'] !== '') {
            $resolved = self::orNoMatch($this->resolveCategoryNameToIds($user, $parsedFilters['category']));
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

    // Case-insensitive prefix match on `accounts.name`; the column stays a
    // literal in the raw LIKE predicate.
    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    private function resolveAccountNamesToIds(User $user, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $query = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where(function (Builder $match) use ($names): void {
                foreach ($names as $name) {
                    $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $name).'%';
                    $match->orWhereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '\\'", [$like]);
                }
            });

        return self::toIntList($query->pluck('id')->all());
    }

    // Matching the stored name alone inverts what the reader sees, because a
    // default category stores English and displays a translation. The stored
    // name is still tried, so the English and a rename both keep working.
    /**
     * @link ../../../../.docs/features/ledger/category-display-names.md
     *
     * @return list<int>
     */
    private function resolveCategoryNameToIds(User $user, string $name): array
    {
        $needle = mb_strtolower($name);

        $rows = $this->db->connection()
            ->table('categories')
            ->where(function (Builder $scope) use ($user): void {
                $scope->where('user_id', $user->id)->orWhereNull('user_id');
            })
            ->get(['id', 'name', 'slug', 'name_is_default']);

        $ids = [];
        foreach ($rows as $row) {
            if (self::startsWith(CategoryDisplayName::fromRow($row) ?? '', $needle) || self::startsWith(self::toString($row->name ?? null), $needle)) {
                $ids[] = self::toInt($row->id ?? null);
            }
        }

        return $ids;
    }

    /**
     * @param  list<int>  $resolved
     * @return list<int>
     */
    private static function orNoMatch(array $resolved): array
    {
        return $resolved === [] ? [self::NO_SUCH_ID] : $resolved;
    }

    private static function startsWith(string $haystack, string $needle): bool
    {
        return $haystack !== '' && str_starts_with(mb_strtolower($haystack), $needle);
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
        $normalize = static fn (string $v): string => str_replace(',', '.', $v);

        return match (true) {
            $token === '' => [$currentMin, $currentMax],
            str_starts_with($token, '>') => [$normalize(substr($token, 1)), $currentMax],
            str_starts_with($token, '<') => [$currentMin, $normalize(substr($token, 1))],
            preg_match('/^(\d+(?:[.,]\d{1,2})?)-(\d+(?:[.,]\d{1,2})?)$/', $token, $m) === 1 => [$normalize($m[1]), $normalize($m[2])],
            preg_match('/^\d+(?:[.,]\d{1,2})?$/', $token) === 1 => [$normalize($token), $normalize($token)],
            default => [$currentMin, $currentMax],
        };
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
                ...CategoryDisplayName::columns('categories'),
                'counterparties.slug as counterparty_slug',
            ]);
    }

    private function applyFilters(Builder $query, User $user, SearchFilters $filters): void
    {
        $this->applyDateFilters($query, $filters);

        if ($filters->accounts !== []) {
            $this->applyOwnershipFilter($query, $user, 'accounts', 'transactions.account_id', $filters->accounts, false);
        }
        if ($filters->categories !== []) {
            $this->applyOwnershipFilter($query, $user, 'categories', 'transactions.category_id', $filters->categories, true);
        }
        if ($filters->counterparties !== []) {
            $this->applyOwnershipFilter($query, $user, 'counterparties', 'transactions.counterparty_id', $filters->counterparties, false);
        }

        $this->applyAmountFilters($query, $filters);
    }

    private function applyDateFilters(Builder $query, SearchFilters $filters): void
    {
        if ($filters->after !== null) {
            $afterDate = strlen($filters->after) === 7 ? $filters->after.'-01' : $filters->after;
            $query->where('transactions.posted_at', '>=', $afterDate);
        }

        $beforeDate = $filters->before === null ? null : $this->normalizeBeforeDate($filters->before);
        if ($beforeDate !== null) {
            $query->where('transactions.posted_at', '<=', $beforeDate);
        }
    }

    // A bare Y-m before-bound covers the whole month, so it widens to that
    // month's last day; an unparseable Y-m yields null and drops the bound
    // rather than clamping to an arbitrary date.
    private function normalizeBeforeDate(string $before): ?string
    {
        if (strlen($before) !== 7) {
            return $before;
        }

        return CarbonImmutable::createFromFormat('Y-m', $before)?->endOfMonth()->toDateString();
    }

    // Validates the supplied ids against the user's own rows (plus global
    // null-user rows when $includeGlobal), then restricts the query to the
    // survivors — or to nothing, so a wholly-foreign id set never leaks
    // another user's rows.
    /**
     * @param  list<int>  $ids
     */
    private function applyOwnershipFilter(
        Builder $query,
        User $user,
        string $table,
        string $column,
        array $ids,
        bool $includeGlobal,
    ): void {
        $validatedIds = $this->db->connection()
            ->table($table)
            ->where(function (Builder $scope) use ($user, $includeGlobal): void {
                $scope->where('user_id', $user->id);
                if ($includeGlobal) {
                    $scope->orWhereNull('user_id');
                }
            })
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        if ($validatedIds !== []) {
            $query->whereIn($column, $validatedIds);
        } else {
            $query->whereRaw(self::MATCH_NOTHING);
        }
    }

    private function applyAmountFilters(Builder $query, SearchFilters $filters): void
    {
        // A filter that will not parse is dropped rather than widened to zero:
        // "> €0" is every row, which is not what the typist asked for.
        $minMinor = $filters->amountMin === null ? null : MoneyInput::tryToMinor($filters->amountMin);
        if ($minMinor !== null) {
            $query->whereRaw('ABS(transactions.settled_amount_minor) >= ?', [$minMinor]);
        }

        $maxMinor = $filters->amountMax === null ? null : MoneyInput::tryToMinor($filters->amountMax);
        if ($maxMinor !== null) {
            $query->whereRaw('ABS(transactions.settled_amount_minor) <= ?', [$maxMinor]);
        }

        if ($filters->amountDirection === 'in') {
            $query->where('transactions.amount_minor', '>', 0);
        } elseif ($filters->amountDirection === 'out') {
            $query->where('transactions.amount_minor', '<', 0);
        }
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
