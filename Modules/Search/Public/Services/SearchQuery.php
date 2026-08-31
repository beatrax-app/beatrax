<?php

declare(strict_types=1);

namespace Modules\Search\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\SafeDate;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Enums\AmountDirection;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\TransactionCursor;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use Modules\Ledger\Public\Support\SplitLegs;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Search\Internal\Services\DidYouMeanSuggester;
use Modules\Search\Internal\Services\FtsCandidateResolver;
use Modules\Search\Internal\Services\QueryParser;
use Modules\Search\Internal\Services\SearchDocumentBody;
use Modules\Search\Internal\Services\SearchRowMapper;
use Modules\Search\Internal\Services\SearchTokenFilters;
use Modules\Search\Public\Contracts\SearchResultsProvider;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Dto\SearchResultPage;
use Modules\Search\Public\Dto\SearchRowDto;

// Full-text search read entry point for /transactions and the ⌘K
// palette: FTS5 MATCH + SQL filters + highlight + cursor pagination.
// Every FTS join and transactions read is scoped by user_id; filter
// IDs are ownership-validated before use.
/**
 * @phpstan-import-type PaletteTransaction from SearchResultsProvider
 */
final readonly class SearchQuery
{
    use CoercesScalars;

    private const string MATCH_NOTHING = '1 = 0';

    private const int PALETTE_HIT_LIMIT = 5;

    public function __construct(
        private DatabaseManager $db,
        private QueryParser $parser,
        private DidYouMeanSuggester $suggester,
        private FtsCandidateResolver $ftsResolver,
        private SearchRowMapper $rowMapper,
        private SearchTokenFilters $tokenFilters,
        private BaseCurrency $baseCurrency,
        private CrossCurrencyTotal $fx,
    ) {}

    public function search(
        User $user,
        string $q,
        SearchFilters $filters,
        ?int $cursorId = null,
        ?string $cursorPostedAt = null,
        int $limit = 50,
    ): SearchResultPage {
        // The reader's own currency, resolved once: it is the scale every
        // amount they type is denominated at, and the currency the totals
        // strip below is labelled with. Resolved before the query is parsed
        // because an `amount:` bound is one of the figures it scales.
        $base = $this->baseCurrency->forUser($user);

        $parsed = $this->parser->parse($q, $base);
        $textQuery = $parsed['textQuery'];
        $parsedFilters = $parsed['filters'];

        $filters = $this->tokenFilters->merge($user, $filters, $parsedFilters, $base);

        // null means "no text query" (filters-only mode) — the base query
        // is scoped by user_id + filters directly, with no whereIn over a
        // materialized id list (avoids the SQLITE_MAX_VARIABLE_NUMBER
        // crash on full history); the fallback scan reuses applyFilters.
        $candidateIds = $this->ftsResolver->resolve(
            $user,
            $textQuery,
            function (Builder $candidateQuery) use ($user, $filters, $base): void {
                $this->applyFilters($candidateQuery, $user, $filters, $base);
            },
        );

        // The parser is the gate, never a shape written beside it: a
        // hand-written two-decimal fraction let a yen reader's "12.50" through
        // to a parse that could only fail, and the null was read as zero.
        $minor = MoneyInput::tryToMinor(trim($textQuery), $base);

        // The amount-query branch only fires when the text branch
        // found no candidates — otherwise a bare numeric query like
        // "2024" would OR in every €2024.00 transaction, conflating
        // "text contains 2024" with "amount is €2024.00".
        if ($candidateIds !== null && $candidateIds === [] && $minor !== null) {
            $candidateIds = self::toIntList(
                $this->db->connection()
                    ->table('transactions')
                    ->where('user_id', $user->id)
                    ->where(function (Builder $inReadersMoney) use ($base, $minor): void {
                        $inReadersMoney
                            ->where(fn (Builder $native): Builder => $native->where('currency', $base)->whereRaw('ABS(amount_minor) = ?', [$minor]))
                            ->orWhere(fn (Builder $settled): Builder => $settled->where('settled_currency', $base)->whereRaw('ABS(settled_amount_minor) = ?', [$minor]));
                    })
                    ->pluck('id')
                    ->all(),
            );
        }

        $query = $this->buildBaseQuery($user, $candidateIds);

        $this->applyFilters($query, $user, $filters, $base);

        // Bucketed by the currency each row settled in and converted from there
        // — counting only the rows already in the reader's own reporting
        // currency reported nothing at all over a ledger denominated elsewhere.
        ['count' => $totalCount, 'out' => $totalOut, 'in' => $totalIn, 'unconverted' => $unconverted] = $this->totals($query, $base);

        $query->limit($limit + 1);
        TransactionCursor::apply($query, $cursorPostedAt, $cursorId);

        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        $sliced = $rows->take($limit)->values();

        $highlights = [];
        if (strlen($textQuery) >= SearchDocumentBody::TRIGRAM_WIDTH && $candidateIds !== null && count($candidateIds) > 0) {
            $highlights = $this->ftsResolver->loadHighlights($user, $textQuery, self::toIntList($sliced->pluck('id')->all()));
        }

        ['rows' => $dtos, 'lastId' => $lastId, 'lastPostedAt' => $lastPostedAt]
            = $this->mapRows($sliced, $highlights, $user);

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
            unconvertedCurrencies: $unconverted,
        );
    }

    // The count travels with the hits because it is the same page's: reading it
    // off a second search ran every FTS match, currency aggregation and
    // did-you-mean corpus build twice per keystroke, for one number.
    /**
     * @return array{hits: list<PaletteTransaction>, totalCount: int}
     */
    public function palette(User $user, string $q): array
    {
        $page = $this->search($user, $q, SearchFilters::empty(), null, null, self::PALETTE_HIT_LIMIT);

        $hits = [];
        foreach ($page->rows as $row) {
            $hits[] = [
                'id' => $row->id,
                'counterpartyName' => $row->counterpartyName,
                // A bill charged every month repeats its counterparty, its
                // amount and its reference, so the day is the only thing on
                // the row that tells two of them apart.
                'date' => $row->postedAt,
                'amount' => $this->rowMapper->formatMinorAmount($row->amountMinor, $row->amountCurrency),
                'snippet' => $row->snippet,
                'url' => '/transactions/'.$row->id,
            ];
        }

        return ['hits' => $hits, 'totalCount' => $page->totalCount];
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

        $query
            ->leftJoin('categories', 'transactions.category_id', '=', 'categories.id')
            ->leftJoin('counterparties', 'transactions.counterparty_id', '=', 'counterparties.id');

        TransactionCursor::orderNewestFirst($query);

        return $query
            ->select([
                'transactions.id',
                'transactions.posted_at',
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

    private function applyFilters(Builder $query, User $user, SearchFilters $filters, string $readerCurrency): void
    {
        $this->applyDateFilters($query, $filters);

        if ($filters->accounts !== []) {
            $this->applyOwnershipFilter($query, $user, 'accounts', 'transactions.account_id', $filters->accounts, false);
        }
        if ($filters->categories !== []) {
            $this->applyCategoryFilter($query, $user, $filters->categories);
        }
        if ($filters->uncategorized) {
            SplitLegs::excludeParents($query)->whereNull('transactions.category_id');
        }
        if ($filters->counterparties !== []) {
            $this->applyOwnershipFilter($query, $user, 'counterparties', 'transactions.counterparty_id', $filters->counterparties, false);
        }

        $this->applyAmountFilters($query, $filters, $readerCurrency);
    }

    // Split-aware, because splitting a transaction is precisely how part of it
    // is attributed to a category: filtering the parent column alone hid every
    // split parent whose LEG a category report had counted, so the list a row
    // opened could not add up to the row.
    /**
     * @param  list<int>  $categoryIds
     */
    private function applyCategoryFilter(Builder $query, User $user, array $categoryIds): void
    {
        $validatedIds = $this->ownedIds($user, 'categories', $categoryIds, true);

        if ($validatedIds === []) {
            $query->whereRaw(self::MATCH_NOTHING);

            return;
        }

        $query->where(static function (Builder $scope) use ($validatedIds): void {
            $scope->whereIn('transactions.category_id', $validatedIds)
                ->orWhereExists(static function (Builder $legs) use ($validatedIds): void {
                    $legs->selectRaw('1')
                        ->from('transaction_splits')
                        ->whereColumn('transaction_splits.transaction_id', 'transactions.id')
                        ->whereIn('transaction_splits.category_id', $validatedIds);
                });
        });
    }

    private function applyDateFilters(Builder $query, SearchFilters $filters): void
    {
        if ($filters->after !== null) {
            $after = self::boundDay($filters->after, endOfMonth: false);
            if ($after === null) {
                $query->whereRaw(self::MATCH_NOTHING);
            } else {
                $query->where('transactions.posted_at', '>=', $after);
            }
        }

        if ($filters->before !== null) {
            $before = self::boundDay($filters->before, endOfMonth: true);
            if ($before === null) {
                $query->whereRaw(self::MATCH_NOTHING);
            } else {
                $query->where('transactions.posted_at', '<=', $before);
            }
        }
    }

    // The bound is compared as a STRING against a DATE column, so an unchecked
    // '2026' matched every 2026 row as an after: bound and none of them as a
    // before: one. A bound this cannot honour matches nothing rather than the
    // whole history, the rule SearchTokenFilters applies to an unresolved name.
    /**
     * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-date-from-outside-normalised-instead-of-refused
     */
    private static function boundDay(string $raw, bool $endOfMonth): ?string
    {
        $trimmed = trim($raw);

        // A bare Y-m is the month, which the two bounds read from opposite
        // ends. Anchored on the 1st before endOfMonth() reads it, since an
        // unset day is filled from TODAY.
        if (preg_match('/^\d{4}-\d{2}$/', $trimmed) === 1) {
            $firstOfMonth = SafeDate::dayOrNull($trimmed.'-01');

            return $firstOfMonth === null
                ? null
                : ($endOfMonth ? $firstOfMonth->endOfMonth() : $firstOfMonth)->toDateString();
        }

        return SafeDate::dayOrNull($trimmed)?->toDateString();
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
        $validatedIds = $this->ownedIds($user, $table, $ids, $includeGlobal);

        if ($validatedIds !== []) {
            $query->whereIn($column, $validatedIds);
        } else {
            $query->whereRaw(self::MATCH_NOTHING);
        }
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function ownedIds(User $user, string $table, array $ids, bool $includeGlobal): array
    {
        return self::toIntList($this->db->connection()
            ->table($table)
            ->where(function (Builder $scope) use ($user, $includeGlobal): void {
                $scope->where('user_id', $user->id);
                if ($includeGlobal) {
                    $scope->orWhereNull('user_id');
                }
            })
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all());
    }

    // Scaled at the READER's currency, never at a hard two decimals: a yen has
    // no minor unit, so "20" became 2 000 of them and dropped every charge the
    // report figure this list opens from had already counted.
    private function applyAmountFilters(Builder $query, SearchFilters $filters, string $readerCurrency): void
    {
        // A filter that will not parse is dropped rather than widened to zero:
        // "> €0" is every row, which is not what the typist asked for.
        $minMinor = $filters->amountMin === null ? null : MoneyInput::tryToMinor($filters->amountMin, $readerCurrency);
        $maxMinor = $filters->amountMax === null ? null : MoneyInput::tryToMinor($filters->amountMax, $readerCurrency);

        // Once for both bounds: applied per bound, a min-and-max search emitted
        // the same settled_currency predicate twice.
        if ($minMinor !== null || $maxMinor !== null) {
            $this->inReadersCurrency($query, $readerCurrency);
        }

        if ($minMinor !== null) {
            $query->whereRaw('ABS(transactions.settled_amount_minor) >= ?', [$minMinor]);
        }

        if ($maxMinor !== null) {
            $query->whereRaw('ABS(transactions.settled_amount_minor) <= ?', [$maxMinor]);
        }

        if ($filters->amountDirection === AmountDirection::In->value) {
            $query->where('transactions.amount_minor', '>', 0);
        } elseif ($filters->amountDirection === AmountDirection::Out->value) {
            $query->where('transactions.amount_minor', '<', 0);
        }

        // A direction alone cannot reconstruct a report figure: a fee and a
        // transfer out are both negative, and neither is counted as spend.
        if ($filters->types !== []) {
            $query->whereIn('transactions.type', $filters->types);
        }
    }

    // The bound is one number in one money, so it can only test rows in that
    // money: ¥13,840 is about €87, and as raw minor units it cleared a bound
    // the chip beside the list rendered as "> €100.00".
    /**
     * @link ../../../../.docs/features/ledger/minor-units-and-zero-decimal-currencies.md#the-other-half-comparing-two-denominations-as-bare-integers
     */
    private function inReadersCurrency(Builder $query, string $readerCurrency): Builder
    {
        return $query->where('transactions.settled_currency', $readerCurrency);
    }

    // Bucketed by the currency each row settled in, then converted — counting
    // only rows already in the reader's currency reported nothing at all over a
    // ledger denominated elsewhere. A bucket no rate reaches is named to the
    // reader rather than left quietly missing from the strip.
    /**
     * @return array{count: int, out: int, in: int, unconverted: list<string>}
     */
    private function totals(Builder $query, string $base): array
    {
        $summary = (clone $query)->selectRaw(
            'COUNT(*) as total_count,
             settled_currency as bucket_currency,
             SUM(CASE WHEN settled_amount_minor < 0 THEN settled_amount_minor ELSE 0 END) as total_out,
             SUM(CASE WHEN settled_amount_minor > 0 THEN settled_amount_minor ELSE 0 END) as total_in',
        )->groupBy('settled_currency')->get();

        $count = 0;
        $outByCurrency = [];
        $inByCurrency = [];

        foreach ($summary as $bucket) {
            $bucketCurrency = is_string($bucket->bucket_currency ?? null) ? $bucket->bucket_currency : '';
            $count += is_numeric($bucket->total_count ?? null) ? (int) $bucket->total_count : 0;
            if ($bucketCurrency === '') {
                continue;
            }
            $outByCurrency[$bucketCurrency] = is_numeric($bucket->total_out ?? null) ? (int) $bucket->total_out : 0;
            $inByCurrency[$bucketCurrency] = is_numeric($bucket->total_in ?? null) ? (int) $bucket->total_in : 0;
        }

        $rates = $this->fx->ratesTo(array_keys($outByCurrency), $base);
        $out = $this->fx->withRates($outByCurrency, $base, $rates);
        $in = $this->fx->withRates($inByCurrency, $base, $rates);

        $unconverted = array_values(array_unique([...$out->unconverted, ...$in->unconverted]));
        sort($unconverted);

        return [
            'count' => $count,
            'out' => $out->minor,
            'in' => $in->minor,
            'unconverted' => $unconverted,
        ];
    }

    // The cursor is the LAST row mapped, so it is carried out of the loop
    // rather than re-derived from a collection the caller has already sliced.
    /**
     * @param  Collection<int, \stdClass>  $sliced
     * @param  array<int, \stdClass>  $highlights
     * @return array{rows: list<SearchRowDto>, lastId: int|null, lastPostedAt: string|null}
     */
    private function mapRows(Collection $sliced, array $highlights, User $user): array
    {
        $rows = [];
        $lastId = null;
        $lastPostedAt = null;

        foreach ($sliced as $row) {
            $rowId = self::toInt($row->id);
            $rows[] = $this->rowMapper->map($row, $highlights[$rowId] ?? null, $user->id);
            $lastId = $rowId;
            $lastPostedAt = self::toString($row->posted_at);
        }

        return ['rows' => $rows, 'lastId' => $lastId, 'lastPostedAt' => $lastPostedAt];
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
