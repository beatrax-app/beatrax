<?php

declare(strict_types=1);

namespace Modules\Chains\Internal;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Chains\Public\Dto\ChainTree;
use Modules\Chains\Public\Dto\ChainTreeNode;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Chains\Public\Enums\ConfidenceTier;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ChainTreeWalker
{
    use CoercesScalars;

    private const MAX_DEPTH = 5;

    private const COUNTERPARTY_SLUG = 'counterparties.slug as counterparty_slug';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
        private readonly BaseCurrency $baseCurrency,
    ) {}

    public function walk(int $transactionId, User $user): ChainTree
    {
        $rootRow = $this->fetchTransactionDisplayRow($transactionId, $user);

        if ($rootRow === null) {
            throw new NotFoundHttpException('Transaction not found.');
        }

        // MAX_DEPTH caps how deep the walk goes, nothing caps how wide: an
        // ics_bulk_settle chain fans out to every card expense in the period.
        // So the account names come back once, not once per node.
        $accountNames = $this->accountNames($user);

        $rootId = self::toInt($rootRow->id);
        $nodes = [];
        $nodes[] = $this->makeNode($rootRow, null, 'root', ConfidenceTier::Confirmed, $user, $accountNames);

        $frontier = [$rootId];
        $visited = [$rootId => true];
        $depth = 0;

        // Both directions: a forward-only walk found nothing whenever the user
        // opened the drawer on a paypal_funding chain's ASN (the `to`) side.
        while ($frontier !== [] && $depth < self::MAX_DEPTH) {
            $frontier = $this->expandFrontier($frontier, $visited, $nodes, $user, $accountNames);
            $depth++;
        }

        return new ChainTree(
            rootTransactionId: $rootId,
            nodes: $nodes,
        );
    }

    /**
     * @param  list<int>  $frontier
     * @param  array<int, true>  $visited
     * @param  list<ChainTreeNode>  $nodes
     * @param  array<int, string>  $accountNames
     * @return list<int>
     */
    private function expandFrontier(array $frontier, array &$visited, array &$nodes, User $user, array $accountNames): array
    {
        $links = $this->db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->where(function (Builder $q) use ($frontier): void {
                $q->whereIn('from_transaction_id', $frontier)
                    ->orWhereIn('to_transaction_id', $frontier);
            })
            ->whereIn('state', [ChainLinkState::Confirmed->value, ChainLinkState::Candidate->value])
            ->orderByDesc('confidence')
            ->get();

        // Claiming every partner first keeps the confidence-ordered link walk
        // and its visited bookkeeping intact, and leaves one whereIn to fetch
        // the level's display rows with.
        $nextFrontier = [];
        $claimed = [];
        foreach ($links as $link) {
            $partnerId = $this->linkPartnerId($link, $visited);
            if ($partnerId === null || isset($visited[$partnerId])) {
                continue;
            }
            $visited[$partnerId] = true;
            $nextFrontier[] = $partnerId;
            $claimed[] = ['link' => $link, 'partner_id' => $partnerId];
        }

        $rows = $this->fetchTransactionDisplayRows($nextFrontier, $user);
        foreach ($claimed as $claim) {
            $this->appendPartnerNode($nodes, $claim['link'], $rows[$claim['partner_id']] ?? null, $accountNames, $user);
        }

        return $nextFrontier;
    }

    /**
     * @param  array<int, true>  $visited
     */
    private function linkPartnerId(stdClass $link, array $visited): ?int
    {
        // NULL to_transaction_id legs (exceeded-tolerance candidates) surface
        // via hintsForReview() instead of walking the tree.
        if ($link->to_transaction_id === null) {
            return null;
        }

        $fromId = self::toInt($link->from_transaction_id);
        $toId = self::toInt($link->to_transaction_id);

        return isset($visited[$fromId]) ? $toId : $fromId;
    }

    /**
     * @param  list<ChainTreeNode>  $nodes
     * @param  array<int, string>  $accountNames
     */
    private function appendPartnerNode(array &$nodes, stdClass $link, ?stdClass $partnerRow, array $accountNames, User $user): void
    {
        if ($partnerRow === null) {
            return;
        }

        $confidenceTier = $this->confidenceTier(
            self::toString($link->state),
            self::toString($link->resolver),
            self::toFloat($link->confidence ?? null),
        );

        $nodes[] = $this->makeNode(
            $partnerRow,
            self::toInt($link->id),
            self::toString($link->kind),
            $confidenceTier,
            $user,
            $accountNames,
        );
    }

    private function confidenceTier(string $state, string $resolver, float $confidence): ConfidenceTier
    {
        if ($state === ChainLinkState::Confirmed->value && $resolver === 'auto' && $confidence === 1.0) {
            return ConfidenceTier::Deterministic;
        }
        if ($state === ChainLinkState::Confirmed->value) {
            return ConfidenceTier::Confirmed;
        }

        return ConfidenceTier::Candidate;
    }

    /**
     * @param  array<int, string>  $accountNames
     */
    private function makeNode(stdClass $row, ?int $chainLinkId, string $kind, ConfidenceTier $tier, User $user, array $accountNames): ChainTreeNode
    {
        $accountName = $accountNames[self::toInt($row->account_id ?? null)] ?? '';
        $currency = self::toString($row->settled_currency ?? null);
        if ($currency === '') {
            $currency = self::toString($row->currency ?? null);
        }
        if ($currency === '') {
            $currency = $this->baseCurrency->code();
        }
        $amountMinor = self::toInt($row->settled_amount_minor ?? $row->amount_minor ?? null);

        return new ChainTreeNode(
            transactionId: self::toInt($row->id),
            chainLinkId: $chainLinkId,
            counterpartyName: $this->decryptCounterpartyName(self::toString($row->counterparty_name ?? null), $user->id),
            amount: Money::ofMinor($amountMinor, $currency),
            bookedAt: CarbonImmutable::parse(self::toString($row->booked_at ?? null)),
            accountName: $accountName,
            kind: $kind,
            confidenceTier: $tier,
            children: [],
            counterpartySlug: self::extractCounterpartySlug($row),
        );
    }

    // The counterparties join carries the slug inline, so the drawer render
    // path never issues a per-node lookup.
    private function fetchTransactionDisplayRow(int $transactionId, User $user): ?stdClass
    {
        $row = $this->displayRowQuery($user)
            ->where('transactions.id', $transactionId)
            ->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * @param  list<int>  $transactionIds
     * @return array<int, stdClass>
     */
    private function fetchTransactionDisplayRows(array $transactionIds, User $user): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $rows = $this->displayRowQuery($user)
            ->whereIn('transactions.id', $transactionIds)
            ->get();

        $byId = [];
        foreach ($rows as $row) {
            $byId[self::toInt($row->id)] = $row;
        }

        return $byId;
    }

    private function displayRowQuery(User $user): Builder
    {
        return $this->db->connection()->table('transactions')
            ->leftJoin('counterparties', 'transactions.counterparty_id', '=', 'counterparties.id')
            ->where('transactions.user_id', $user->id)
            ->select([
                'transactions.id',
                'transactions.counterparty_name',
                'transactions.amount_minor',
                'transactions.currency',
                'transactions.settled_amount_minor',
                'transactions.settled_currency',
                'transactions.booked_at',
                'transactions.account_id',
                self::COUNTERPARTY_SLUG,
            ]);
    }

    /**
     * @return array<int, string> keyed by account id; an id absent here is one the
     *                            user does not own, and its node shows no account name
     */
    private function accountNames(User $user): array
    {
        $rows = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->get(['id', 'name']);

        $names = [];
        foreach ($rows as $row) {
            $names[self::toInt($row->id)] = self::toString($row->name);
        }

        return $names;
    }

    private function decryptCounterpartyName(?string $raw, int $userId): string
    {
        $stored = $raw ?? '';
        if ($stored === '') {
            return '';
        }

        return $this->codec->decryptValue('transactions', 'counterparty_name', $stored, $userId, ($this->session)())['value'];
    }

    private static function extractCounterpartySlug(stdClass $row): ?string
    {
        if (! property_exists($row, 'counterparty_slug') || $row->counterparty_slug === null) {
            return null;
        }
        $slug = self::toString($row->counterparty_slug);

        return $slug === '' ? null : $slug;
    }

    private static function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
