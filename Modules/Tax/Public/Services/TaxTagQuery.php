<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Counterparties\Public\Support\CounterpartyDefaultName;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Internal\Support\TaggedRowScope;
use Modules\Tax\Internal\Support\TaxCorpusWording;
use Modules\Tax\Public\Dto\BatchTagSuggestion;
use Modules\Tax\Public\Dto\TaxTagData;
use Modules\Tax\Public\Dto\TaxYearSummary;

final readonly class TaxTagQuery
{
    // Not a SQL COALESCE any more: the wording is resolved per reader, so the
    // choice between the short label and the name has to be made after both
    // have been resolved, not by the database. A category the reader added has
    // no short label, and still falls through to its name.
    private const array CATEGORY_BADGE_COLUMNS = [
        'cat.short_name AS category_short_name',
        'cat.name AS category_name',
        'cat.corpus_key AS category_corpus_key',
        'cat.country_code AS category_country_code',
        'cat.name_is_default AS category_name_is_default',
    ];

    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
        private CrossCurrencyTotal $fx,
        private BaseCurrency $baseCurrency,
    ) {}

    // Whole-transaction tags only; callers that need leg-aware state use
    // forTransactionIdsWithLegs() instead.
    /**
     * @param  array<int>  $transactionIds
     * @return array<int, TaxTagData>
     */
    public function forTransactionIds(int $userId, array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $rows = $this->db->connection()
            ->table(TaggedRowScope::TAGS)
            ->leftJoin('tax_deduction_categories AS cat', 'cat.id', '=', 'tag.deduction_category_id')
            ->where('tag.user_id', $userId)
            ->whereIn('tag.transaction_id', $transactionIds)
            ->whereNull('tag.transaction_split_id')
            ->get([
                'tag.transaction_id',
                'tag.deduction_category_id',
                ...self::CATEGORY_BADGE_COLUMNS,
                'tag.note',
                'tag.tax_year_override',
            ]);

        $map = [];
        foreach ($rows as $row) {
            $txId = self::toInt($row->transaction_id);
            $map[$txId] = new TaxTagData(
                transactionId: $txId,
                deductionCategoryId: $row->deduction_category_id !== null ? self::toInt($row->deduction_category_id) : null,
                deductionCategoryShortName: self::badgeLabel($row),
                note: $this->decryptNoteOrNull($row->note, $userId),
                taxYearOverride: $row->tax_year_override !== null ? self::toInt($row->tax_year_override) : null,
            );
        }

        return $map;
    }

    /**
     * @param  array<int>  $transactionIds
     * @return array<string, TaxTagData>
     */
    public function forTransactionIdsWithLegs(int $userId, array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $rows = $this->db->connection()
            ->table(TaggedRowScope::TAGS)
            ->leftJoin('tax_deduction_categories AS cat', 'cat.id', '=', 'tag.deduction_category_id')
            ->where('tag.user_id', $userId)
            ->whereIn('tag.transaction_id', $transactionIds)
            ->get([
                'tag.transaction_id',
                'tag.transaction_split_id',
                'tag.deduction_category_id',
                ...self::CATEGORY_BADGE_COLUMNS,
                'tag.note',
                'tag.tax_year_override',
            ]);

        $map = [];
        foreach ($rows as $row) {
            $txId = self::toInt($row->transaction_id);
            $splitId = $row->transaction_split_id !== null ? self::toInt($row->transaction_split_id) : null;
            $key = $txId.':'.($splitId !== null ? (string) $splitId : 'whole');

            $map[$key] = new TaxTagData(
                transactionId: $txId,
                deductionCategoryId: $row->deduction_category_id !== null ? self::toInt($row->deduction_category_id) : null,
                deductionCategoryShortName: self::badgeLabel($row),
                note: $this->decryptNoteOrNull($row->note, $userId),
                taxYearOverride: $row->tax_year_override !== null ? self::toInt($row->tax_year_override) : null,
                transactionSplitId: $splitId,
            );
        }

        return $map;
    }

    // Used by the tag picker to decide whether to render the year-assignment
    // row (posted year != current tax year). The same day TaggedRowScope
    // buckets on, or the picker offers to move a row off a year it is not on.
    public function postedYearFor(int $userId, int $transactionId): ?int
    {
        $postedAt = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->value('posted_at');

        if (! is_string($postedAt) || strlen($postedAt) < 4) {
            return null;
        }

        $year = (int) substr($postedAt, 0, 4);

        return $year > 0 ? $year : null;
    }

    // totalMinor is the deductions total only; count covers every tagged
    // item regardless of type. Grouped by settled_currency and converted per
    // bucket: a dollar receipt's cents are not the reader's.
    public function summaryForUser(int $userId, int $year): TaxYearSummary
    {
        $connection = $this->db->connection();

        $query = $connection
            ->table(TaggedRowScope::TAGS)
            ->join(TaggedRowScope::TRANSACTIONS, 't.id', '=', 'tag.transaction_id')
            ->where('tag.user_id', $userId)
            ->whereRaw(TaggedRowScope::EFFECTIVE_YEAR.' = ?', [$year]);

        TaggedRowScope::joinLegs($query);
        TaggedRowScope::withoutSuperseded($query, $connection);

        $rows = $query
            ->groupBy('t.settled_currency')
            ->selectRaw(
                't.settled_currency, COUNT(*) AS cnt, SUM(CASE WHEN t.type = ? THEN 0 ELSE ABS('.TaggedRowScope::SETTLED_AMOUNT_MINOR.') END) AS bucket_minor',
                [TransactionType::Income->value],
            )
            ->get();

        $byCurrency = [];
        $count = 0;
        foreach ($rows as $row) {
            $byCurrency[self::toString($row->settled_currency)] = self::toInt($row->bucket_minor);
            $count += self::toInt($row->cnt);
        }

        $total = $this->fx->of($byCurrency, $this->baseCurrency->code());

        return new TaxYearSummary(
            year: $year,
            totalMinor: $total->minor,
            count: $count,
            currency: $total->currency,
            unconvertedCurrencies: $total->unconverted,
        );
    }

    // Returns untaggedCount=0 when the transaction has no counterparty;
    // the count excludes the just-tagged transaction itself.
    /**
     * @link ../../../../.docs/features/counterparties/resolution-chain.md#the-apps-own-words-for-a-row-it-had-to-name
     */
    public function untaggedCountForCounterparty(int $userId, int $transactionId, int $taxYear): BatchTagSuggestion
    {
        $connection = $this->db->connection();

        $tx = $connection
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->first(['counterparty_id']);

        if ($tx === null || $tx->counterparty_id === null) {
            return new BatchTagSuggestion(
                counterpartyId: 0,
                counterpartyName: '',
                untaggedCount: 0,
            );
        }

        $cpId = self::toInt($tx->counterparty_id);

        $cpRow = $connection
            ->table('counterparties')
            ->where('id', $cpId)
            ->first(['display_name', 'metadata']);

        // Decrypt first, then translate: the seam reads the plaintext name,
        // and `metadata` is not a sensitive column. The banner names the
        // counterparty inside a sentence, so a row the app named itself has to
        // read in the same language as the rest of it.
        $cpName = $cpRow !== null && is_string($cpRow->display_name)
            ? CounterpartyDefaultName::resolve(
                $this->codec->decryptValue('counterparties', 'display_name', $cpRow->display_name, $userId, ($this->session)())['value'],
                $cpRow->metadata,
            )
            : '';

        $untaggedCount = $this->untaggedForCounterparty($userId, $cpId, $taxYear)
            ->where('t.id', '!=', $transactionId)
            ->count();

        return new BatchTagSuggestion(
            counterpartyId: $cpId,
            counterpartyName: $cpName,
            untaggedCount: $untaggedCount,
        );
    }

    /**
     * @return list<int>
     */
    public function untaggedIdsForCounterparty(int $userId, int $counterpartyId, int $taxYear): array
    {
        $rows = $this->untaggedForCounterparty($userId, $counterpartyId, $taxYear)->pluck('t.id');

        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows->all()));
    }

    // One predicate behind the banner's offer and the banner's write, so the
    // two cannot say different numbers. A reconcile freezes exactly the
    // classification a tag is, so a row it covers is neither counted nor
    // tagged — counting them offered seven and wrote three.
    private function untaggedForCounterparty(int $userId, int $counterpartyId, int $taxYear): QueryBuilder
    {
        return $this->db->connection()
            ->table(TaggedRowScope::TRANSACTIONS)
            ->leftJoin(TaggedRowScope::TAGS, static function (JoinClause $join) use ($userId): void {
                $join->on('tag.transaction_id', '=', 't.id')
                    ->where('tag.user_id', '=', $userId);
            })
            ->whereNull('tag.id')
            ->where('t.user_id', $userId)
            ->where('t.counterparty_id', $counterpartyId)
            ->where('t.status', '!=', ClearedStatus::Reconciled->value)
            ->whereRaw(
                TaggedRowScope::TRANSACTION_YEAR.' = ?',
                [$taxYear],
            );
    }

    // What the badge on a transaction row prints: the corpus's short label in
    // the reader's language, falling through to the category's own name for a
    // row the reader added, which has no short label and no key to resolve.
    private static function badgeLabel(\stdClass $row): ?string
    {
        $country = self::toStringOrNull($row->category_country_code);
        $key = self::toStringOrNull($row->category_corpus_key);

        return TaxCorpusWording::shortName(self::toStringOrNull($row->category_short_name), $country, $key)
            ?? TaxCorpusWording::name(
                self::toStringOrNull($row->category_name),
                $country,
                $key,
                $row->category_name_is_default,
            );
    }

    private function decryptNoteOrNull(mixed $value, int $userId): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $this->codec->decryptValue('tax_transaction_tags', 'note', $value, $userId, ($this->session)())['value'];
    }
}
