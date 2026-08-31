<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Modules\Chains\Internal\ChainTreeWalker;
use Modules\Chains\Internal\Dto\SettlementTotals;
use Modules\Chains\Internal\Presentation\ChainLinkRowFactory;
use Modules\Chains\Public\Dto\ChainLinkHintRow;
use Modules\Chains\Public\Dto\ChainLinkRow;
use Modules\Chains\Public\Dto\ChainTree;
use Modules\Chains\Public\Dto\SeriesFunderLink;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

final readonly class ChainLinkQuery
{
    use CoercesScalars;

    // How many settlements one /chains page carries, and how many legs each of
    // their cards lists. A settled ICS statement covers 50 to 300 charges, so
    // the second bound is what keeps the page a page.
    public const int SETTLEMENTS_PER_PAGE = 20;

    public const int LEGS_PER_SETTLEMENT = 12;

    public function __construct(
        private DatabaseManager $db,
        private ChainLinkRowFactory $rowFactory,
        private ChainTreeWalker $treeWalker,
        private BaseCurrency $baseCurrency,
    ) {}

    public function forTransaction(int $transactionId, User $user): ChainTree
    {
        return $this->treeWalker->walk($transactionId, $user);
    }

    // Settlements, not links: a settled ICS statement covers 50 to 300 charges,
    // so a flat page of links cut inside one and the card built from the slice
    // stated a count and a total the settlement heading above it contradicted.
    // The legs stay capped; settlementTotalsForUser() states the true figures.
    /**
     * @return list<ChainLinkRow> ordered settlement by settlement, newest settlement first
     */
    public function allChainsForUser(
        User $user,
        int $settlementLimit = self::SETTLEMENTS_PER_PAGE,
        int $legsPerSettlement = self::LEGS_PER_SETTLEMENT,
    ): array {
        $settlementIds = $this->newestSettlementIds($user, $settlementLimit);
        if ($settlementIds === []) {
            return [];
        }

        $linkIds = $this->displayedLegLinkIds($user, $settlementIds, $legsPerSettlement);
        if ($linkIds === []) {
            return [];
        }

        return $this->rowFactory->chainLinkRows($this->chainLinkRowsByIds($user, $linkIds), $user);
    }

    // One aggregate per settlement over every leg it has, so the card can state
    // a count and a per-currency total that the legs it lists are a prefix of.
    /**
     * @return list<SettlementTotals>
     */
    public function settlementTotalsForUser(User $user, int $settlementLimit = self::SETTLEMENTS_PER_PAGE): array
    {
        $settlementIds = $this->newestSettlementIds($user, $settlementLimit);
        if ($settlementIds === []) {
            return [];
        }

        /** @var array<int, array{count: int, candidate: bool, totals: array<string, int>}> $accumulated */
        $accumulated = [];
        foreach ([true, false] as $icsArm) {
            foreach ($this->settlementTotalRows($user, $settlementIds, $icsArm) as $row) {
                /** @var stdClass $row */
                $settlementId = self::toInt($row->settlement_id ?? null);
                $currency = self::toString($row->currency ?? null);
                $bucket = $accumulated[$settlementId] ?? ['count' => 0, 'candidate' => false, 'totals' => []];
                $bucket['count'] += self::toInt($row->leg_count ?? null);
                $bucket['candidate'] = $bucket['candidate'] || self::toInt($row->has_candidate ?? null) === 1;
                $bucket['totals'][$currency] = ($bucket['totals'][$currency] ?? 0) + self::toInt($row->total_minor ?? null);
                $accumulated[$settlementId] = $bucket;
            }
        }

        $result = [];
        foreach ($settlementIds as $settlementId) {
            $bucket = $accumulated[$settlementId] ?? null;
            if ($bucket === null) {
                continue;
            }
            $totals = [];
            foreach ($bucket['totals'] as $currency => $minor) {
                $totals[] = Money::ofMinor($minor, $currency !== '' ? $currency : $this->baseCurrency->code());
            }
            $result[] = new SettlementTotals(
                settlementTransactionId: $settlementId,
                legCount: $bucket['count'],
                totals: $totals,
                hasCandidateLeg: $bucket['candidate'],
            );
        }

        return $result;
    }

    // Which endpoint is the settlement depends on the kind, so each side is
    // asked separately rather than through a CASE the query builder would have
    // to carry raw. Both arms take the same limit: the newest N settlements
    // overall are a subset of the union of each arm's newest N.
    /**
     * @return list<int>
     */
    private function newestSettlementIds(User $user, int $limit): array
    {
        /** @var array<int, array{0: string, 1: int}> $newestBySettlement */
        $newestBySettlement = [];

        foreach ([true, false] as $icsArm) {
            $column = $icsArm ? 'from_transaction_id' : 'to_transaction_id';
            $query = $this->baseLinkQuery($user, $icsArm)
                ->groupBy($column)
                ->select($column.' as settlement_id')
                ->selectRaw('max(created_at) as newest_at, max(id) as newest_id')
                ->orderByDesc('newest_at')
                ->orderByDesc('newest_id')
                ->limit($limit);

            foreach ($query->get() as $row) {
                /** @var stdClass $row */
                $settlementId = self::toInt($row->settlement_id ?? null);
                $stamp = [self::toString($row->newest_at ?? null), self::toInt($row->newest_id ?? null)];
                if (! isset($newestBySettlement[$settlementId]) || $stamp > $newestBySettlement[$settlementId]) {
                    $newestBySettlement[$settlementId] = $stamp;
                }
            }
        }

        uasort($newestBySettlement, static fn (array $a, array $b): int => $b <=> $a);

        return array_slice(array_keys($newestBySettlement), 0, $limit);
    }

    // Two columns per row and no evidence blob: this read covers every leg of
    // every settlement on the page, and only the ones that fit are hydrated.
    /**
     * @param  list<int>  $settlementIds
     * @return list<int>
     */
    private function displayedLegLinkIds(User $user, array $settlementIds, int $legsPerSettlement): array
    {
        $rows = $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->whereIn('state', [ChainLinkState::Confirmed->value, ChainLinkState::Candidate->value])
            ->whereNotNull('to_transaction_id')
            ->where(function (Builder $q) use ($settlementIds): void {
                $q->where(function (Builder $ics) use ($settlementIds): void {
                    $ics->where('kind', ChainLinkKind::IcsBulkSettle->value)
                        ->whereIn('from_transaction_id', $settlementIds);
                })->orWhere(function (Builder $other) use ($settlementIds): void {
                    $other->where('kind', '!=', ChainLinkKind::IcsBulkSettle->value)
                        ->whereIn('to_transaction_id', $settlementIds);
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'kind', 'from_transaction_id', 'to_transaction_id']);

        /** @var array<int, list<int>> $bySettlement */
        $bySettlement = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $isIcs = self::toString($row->kind ?? null) === ChainLinkKind::IcsBulkSettle->value;
            $settlementId = self::toInt($isIcs ? ($row->from_transaction_id ?? null) : ($row->to_transaction_id ?? null));
            if (count($bySettlement[$settlementId] ?? []) >= $legsPerSettlement) {
                continue;
            }
            $bySettlement[$settlementId][] = self::toInt($row->id ?? null);
        }

        $ordered = [];
        foreach ($settlementIds as $settlementId) {
            foreach ($bySettlement[$settlementId] ?? [] as $linkId) {
                $ordered[] = $linkId;
            }
        }

        return $ordered;
    }

    /**
     * @param  list<int>  $linkIds
     * @return list<stdClass>
     */
    private function chainLinkRowsByIds(User $user, array $linkIds): array
    {
        $rows = $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->whereIn('id', $linkIds)
            ->get();

        $byId = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $byId[self::toInt($row->id ?? null)] = $row;
        }

        $ordered = [];
        foreach ($linkIds as $linkId) {
            if (isset($byId[$linkId])) {
                $ordered[] = $byId[$linkId];
            }
        }

        return $ordered;
    }

    /**
     * @param  list<int>  $settlementIds
     * @return Collection<int, stdClass>
     */
    private function settlementTotalRows(User $user, array $settlementIds, bool $icsArm): Collection
    {
        $settlementColumn = $icsArm ? 'from_transaction_id' : 'to_transaction_id';
        $legColumn = $icsArm ? 'to_transaction_id' : 'from_transaction_id';

        return $this->baseLinkQuery($user, $icsArm)
            ->join('transactions as leg', 'leg.id', '=', 'chain_links.'.$legColumn)
            ->where('leg.user_id', $user->id)
            ->whereIn('chain_links.'.$settlementColumn, $settlementIds)
            ->groupBy('chain_links.'.$settlementColumn, 'leg.settled_currency')
            ->select(
                'chain_links.'.$settlementColumn.' as settlement_id',
                'leg.settled_currency as currency',
            )
            ->selectRaw('count(*) as leg_count, sum(leg.settled_amount_minor) as total_minor')
            ->selectRaw('max(case when chain_links.state = ? then 1 else 0 end) as has_candidate', [ChainLinkState::Candidate->value])
            ->get();
    }

    private function baseLinkQuery(User $user, bool $icsArm): Builder
    {
        $query = $this->db->connection()->table('chain_links')
            ->where('chain_links.user_id', $user->id)
            ->whereIn('chain_links.state', [ChainLinkState::Confirmed->value, ChainLinkState::Candidate->value])
            ->whereNotNull('chain_links.to_transaction_id');

        return $icsArm
            ? $query->where('chain_links.kind', ChainLinkKind::IcsBulkSettle->value)
            : $query->where('chain_links.kind', '!=', ChainLinkKind::IcsBulkSettle->value);
    }

    public function hasChainForTransaction(int $transactionId, User $user): bool
    {
        return $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->whereIn('state', [ChainLinkState::Confirmed->value, ChainLinkState::Candidate->value])
            ->where(function (Builder $q) use ($transactionId): void {
                $q->where('from_transaction_id', $transactionId)
                    ->orWhere('to_transaction_id', $transactionId);
            })
            ->exists();
    }

    // Forecasting's chain-aware router rewrites contribution account ids onto
    // these funders; an empty result leaves the contribution where it is.
    /**
     * @return list<SeriesFunderLink>
     */
    public function confirmedFundersForSeries(int $seriesId, User $user): array
    {
        $rows = $this->db->connection()->table('chain_links')
            ->join('recurring_series_occurrences as rso', 'rso.transaction_id', '=', 'chain_links.from_transaction_id')
            ->join('transactions as funder_tx', 'funder_tx.id', '=', 'chain_links.to_transaction_id')
            ->where('chain_links.user_id', $user->id)
            ->where('rso.recurring_series_id', $seriesId)
            // state alone. A rejected link is not confirmed, so the resolver
            // column adds nothing here — what it did add was excluding every
            // link the auto-promotion loop confirmed, which writes 'rule'.
            ->where('chain_links.state', ChainLinkState::Confirmed->value)
            ->whereNotNull('chain_links.to_transaction_id')
            ->select(
                'chain_links.id as chain_link_id',
                'chain_links.from_transaction_id as from_transaction_id',
                'chain_links.to_transaction_id as to_transaction_id',
                'funder_tx.account_id as funder_account_id',
                'chain_links.kind as kind',
                'chain_links.state as state',
                'chain_links.resolver as resolver',
                'chain_links.confidence as confidence',
            )
            // The router takes the first row as the funder, so the order is
            // part of the answer: strongest evidence first, oldest link
            // breaking a tie.
            ->orderByDesc('chain_links.confidence')
            ->orderBy('chain_links.id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $result[] = new SeriesFunderLink(
                chainLinkId: self::toInt($row->chain_link_id),
                fromTransactionId: self::toInt($row->from_transaction_id),
                toTransactionId: self::toInt($row->to_transaction_id),
                funderAccountId: self::toInt($row->funder_account_id),
                kind: self::toString($row->kind),
                state: self::toString($row->state),
                resolver: self::toString($row->resolver),
                confidence: self::toFloat($row->confidence),
            );
        }

        return $result;
    }

    // The sidebar badge routes to /chains/review, which filters NULL-endpoint
    // rows out as unactionable — so this counts the same set candidatesForReview()
    // returns. hintCount() is the badge for the hints those rows belong to.
    public function openCandidateCount(User $user): int
    {
        return self::toInt(
            $this->db->connection()->table('chain_links')
                ->where('user_id', $user->id)
                ->where('state', ChainLinkState::Candidate->value)
                ->whereNotNull('to_transaction_id')
                ->count(),
        );
    }

    // Keyset cursor: the (confidence, id) tuple of the previous page's last
    // row, compared lexicographically so ties on confidence stay stable.
    /**
     * @return list<ChainLinkRow>
     */
    public function candidatesForReview(
        User $user,
        ?int $cursorId = null,
        ?string $cursorConfidence = null,
        int $limit = 26,
    ): array {
        // Hint-shaped rows (to_transaction_id IS NULL) have no confirm/reject
        // path, so the queue filters them out as unactionable.
        $query = $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->where('state', ChainLinkState::Candidate->value)
            ->whereNotNull('to_transaction_id')
            ->orderByDesc('confidence')
            ->orderByDesc('id')
            ->limit($limit);

        if ($cursorId !== null && $cursorConfidence !== null) {
            $query->where(function ($q) use ($cursorConfidence, $cursorId): void {
                /** @var Builder $q */
                $q->where('confidence', '<', $cursorConfidence)
                    ->orWhere(function ($qq) use ($cursorConfidence, $cursorId): void {
                        /** @var Builder $qq */
                        $qq->where('confidence', '=', $cursorConfidence)
                            ->where('id', '<', $cursorId);
                    });
            });
        }

        /** @var list<stdClass> $rows */
        $rows = $query->get()->all();

        return $this->rowFactory->chainLinkRows($rows, $user);
    }

    /**
     * @return list<ChainLinkHintRow>
     */
    public function hintsForReview(User $user): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->where('state', ChainLinkState::Candidate->value)
            ->whereNull('to_transaction_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->all();

        return $this->rowFactory->hintRows($rows, $user);
    }

    public function hintCount(User $user): int
    {
        return $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->where('state', ChainLinkState::Candidate->value)
            ->whereNull('to_transaction_id')
            ->count();
    }
}
