<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Public\Dto\BatchTagSuggestion;
use Modules\Tax\Public\Dto\TaxTagData;
use Modules\Tax\Public\Dto\TaxYearSummary;

final class TaxTagQuery
{
    private const TAX_TAGS_ALIAS = 'tax_transaction_tags AS tag';

    private const TRANSACTIONS_ALIAS = 'transactions AS t';

    use CoercesScalars;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
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
            ->table(self::TAX_TAGS_ALIAS)
            ->leftJoin('tax_deduction_categories AS cat', 'cat.id', '=', 'tag.deduction_category_id')
            ->where('tag.user_id', $userId)
            ->whereIn('tag.transaction_id', $transactionIds)
            ->whereNull('tag.transaction_split_id')
            ->get([
                'tag.transaction_id',
                'tag.deduction_category_id',
                'cat.short_name AS category_short_name',
                'tag.note',
                'tag.tax_year_override',
            ]);

        $map = [];
        foreach ($rows as $row) {
            $txId = self::toInt($row->transaction_id);
            $map[$txId] = new TaxTagData(
                transactionId: $txId,
                deductionCategoryId: $row->deduction_category_id !== null ? self::toInt($row->deduction_category_id) : null,
                deductionCategoryShortName: self::toStringOrNull($row->category_short_name),
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
            ->table(self::TAX_TAGS_ALIAS)
            ->leftJoin('tax_deduction_categories AS cat', 'cat.id', '=', 'tag.deduction_category_id')
            ->where('tag.user_id', $userId)
            ->whereIn('tag.transaction_id', $transactionIds)
            ->get([
                'tag.transaction_id',
                'tag.transaction_split_id',
                'tag.deduction_category_id',
                'cat.short_name AS category_short_name',
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
                deductionCategoryShortName: self::toStringOrNull($row->category_short_name),
                note: $this->decryptNoteOrNull($row->note, $userId),
                taxYearOverride: $row->tax_year_override !== null ? self::toInt($row->tax_year_override) : null,
                transactionSplitId: $splitId,
            );
        }

        return $map;
    }

    // Used by the tag picker to decide whether to render the
    // year-assignment row (booked year != current tax year).
    public function bookedYearFor(int $userId, int $transactionId): ?int
    {
        $bookedAt = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->value('booked_at');

        if (! is_string($bookedAt) || strlen($bookedAt) < 4) {
            return null;
        }

        $year = (int) substr($bookedAt, 0, 4);

        return $year > 0 ? $year : null;
    }

    // totalMinor is the deductions total only; count covers every tagged
    // item regardless of type.
    public function summaryForUser(int $userId, int $year): TaxYearSummary
    {
        $row = $this->db->connection()
            ->table(self::TAX_TAGS_ALIAS)
            ->join(self::TRANSACTIONS_ALIAS, 't.id', '=', 'tag.transaction_id')
            ->where('tag.user_id', $userId)
            ->whereRaw(
                'COALESCE(tag.tax_year_override, CAST(strftime(\'%Y\', t.booked_at) AS INTEGER)) = ?',
                [$year],
            )
            ->selectRaw(
                'COUNT(*) AS cnt, SUM(CASE WHEN t.type = ? THEN 0 ELSE ABS(t.settled_amount_minor) END) AS total_minor',
                [TransactionType::Income->value],
            )
            ->first();

        return new TaxYearSummary(
            year: $year,
            totalMinor: $row !== null ? self::toInt($row->total_minor) : 0,
            count: $row !== null ? self::toInt($row->cnt) : 0,
        );
    }

    // Returns untaggedCount=0 when the transaction has no counterparty;
    // the count excludes the just-tagged transaction itself.
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
            ->first(['display_name']);

        $cpName = $cpRow !== null && is_string($cpRow->display_name)
            ? $this->codec->decryptValue('counterparties', 'display_name', $cpRow->display_name, $userId, ($this->session)())['value']
            : '';

        $untaggedCount = $connection
            ->table(self::TRANSACTIONS_ALIAS)
            ->leftJoin(self::TAX_TAGS_ALIAS, static function (JoinClause $join) use ($userId): void {
                $join->on('tag.transaction_id', '=', 't.id')
                    ->where('tag.user_id', '=', $userId);
            })
            ->whereNull('tag.id')
            ->where('t.user_id', $userId)
            ->where('t.counterparty_id', $cpId)
            ->where('t.id', '!=', $transactionId)
            ->whereRaw(
                'CAST(strftime(\'%Y\', t.booked_at) AS INTEGER) = ?',
                [$taxYear],
            )
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
        $rows = $this->db->connection()
            ->table(self::TRANSACTIONS_ALIAS)
            ->leftJoin(self::TAX_TAGS_ALIAS, static function (JoinClause $join) use ($userId): void {
                $join->on('tag.transaction_id', '=', 't.id')
                    ->where('tag.user_id', '=', $userId);
            })
            ->whereNull('tag.id')
            ->where('t.user_id', $userId)
            ->where('t.counterparty_id', $counterpartyId)
            ->whereRaw(
                'CAST(strftime(\'%Y\', t.booked_at) AS INTEGER) = ?',
                [$taxYear],
            )
            ->pluck('t.id');

        return array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows->all()));
    }

    private function decryptNoteOrNull(mixed $value, int $userId): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $this->codec->decryptValue('tax_transaction_tags', 'note', $value, $userId, ($this->session)())['value'];
    }
}
