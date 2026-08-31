<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Ledger\Public\Support\CategoryPathName;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Public\Services\TaxTagQuery;

// The two batched reads a rendered transaction row needs beyond the row itself:
// its split legs, and whether a chain links it to anything. Both span the whole
// accumulated list rather than the current page, and both were private on the
// component with their four collaborators threaded in per call.
final readonly class TransactionRowDecorator
{
    public function __construct(
        private DatabaseManager $db,
        private TaxTagQuery $taxTagQuery,
        private SensitiveColumnCodec $codec,
        private Session $session,
    ) {}

    // Two narrow readers rather than a public property each: HandlesTaxTagging
    // and HandlesClearedStatus are traits on the component and take their
    // collaborator as an argument, so the component still has to hand it over.
    public function taxTags(): TaxTagQuery
    {
        return $this->taxTagQuery;
    }

    public function database(): DatabaseManager
    {
        return $this->db;
    }

    /**
     * @param  list<int>  $transactionIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function legsFor(array $transactionIds, int $userId, string $readerCurrency): array
    {
        if ($transactionIds === []) {
            return [];
        }

        // The leg's own picker offers the qualified name, so a leg row showing
        // the bare leaf named one category two ways in one panel.
        $legs = $this->db->connection()
            ->table('transaction_splits')
            ->leftJoin('categories', 'transaction_splits.category_id', '=', 'categories.id');

        $rows = CategoryPathName::joinParent($legs, $userId, 'categories', 'parent_categories')
            ->whereIn('transaction_splits.transaction_id', $transactionIds)
            ->orderBy('transaction_splits.transaction_id')
            ->orderBy('transaction_splits.sort_order')
            ->get([
                'transaction_splits.id',
                'transaction_splits.transaction_id',
                'transaction_splits.settled_amount_minor',
                'transaction_splits.settled_currency',
                'transaction_splits.note',
                ...CategoryPathName::columns('categories', 'parent_categories'),
            ]);

        // Leg-scoped tax state — one batched query, keyed by
        // "{txId}:{legId}". Not merged into $taxState (whole-transaction only).
        $legTaxState = $this->taxTagQuery->forTransactionIdsWithLegs($userId, $transactionIds);

        $map = [];
        foreach ($rows as $row) {
            $txId = is_numeric($row->transaction_id) ? (int) $row->transaction_id : 0;
            $legId = is_numeric($row->id) ? (int) $row->id : 0;
            $legTag = $legTaxState[$txId.':'.$legId] ?? null;

            $legNote = is_string($row->note)
                ? $this->codec->decryptValue('transaction_splits', 'note', $row->note, $userId, $this->session)['value']
                : null;

            $map[$txId] ??= [];
            $map[$txId][] = [
                'id' => $legId,
                'categoryName' => CategoryPathName::fromRow($row) ?? '—',
                'amountMinor' => is_numeric($row->settled_amount_minor) ? (int) $row->settled_amount_minor : 0,
                'amountCurrency' => is_string($row->settled_currency) ? $row->settled_currency : $readerCurrency,
                'note' => $legNote,
                'taxTagged' => $legTag !== null,
                'taxCategoryShortName' => $legTag->deductionCategoryShortName ?? null,
            ];
        }

        return $map;
    }

    /**
     * @param  list<int>  $rowIds
     * @return array<int, bool>
     */
    public function chainTxIdsFor(array $rowIds, int $userId): array
    {
        if ($rowIds === []) {
            return [];
        }

        $matches = $this->db->connection()->table('chain_links')
            ->where('user_id', $userId)
            ->whereIn('state', ['confirmed', 'candidate'])
            ->where(function (Builder $q) use ($rowIds): void {
                $q->whereIn('from_transaction_id', $rowIds)
                    ->orWhereIn('to_transaction_id', $rowIds);
            })
            ->select(['from_transaction_id', 'to_transaction_id'])
            ->get();

        $chainTxIds = [];
        foreach ($matches as $m) {
            $fromId = is_numeric($m->from_transaction_id) ? (int) $m->from_transaction_id : 0;
            $toId = is_numeric($m->to_transaction_id ?? null) ? (int) $m->to_transaction_id : 0;
            if ($fromId !== 0) {
                $chainTxIds[$fromId] = true;
            }
            if ($toId !== 0) {
                $chainTxIds[$toId] = true;
            }
        }

        return $chainTxIds;
    }
}
