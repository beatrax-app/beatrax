<?php

declare(strict_types=1);

namespace Modules\Chains\Internal;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Chains\Public\Dto\ChainTree;
use Modules\Chains\Public\Dto\ChainTreeNode;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
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
    ) {}

    public function walk(int $transactionId, User $user): ChainTree
    {
        $rootRow = $this->fetchTransactionDisplayRow($transactionId, $user);

        if ($rootRow === null) {
            throw new NotFoundHttpException('Transaction not found.');
        }

        $rootId = self::toInt($rootRow->id);
        $nodes = [];
        $nodes[] = $this->makeNode($rootRow, null, 'root', 'Confirmed', $user);

        $frontier = [$rootId];
        $visited = [$rootId => true];
        $depth = 0;

        // Both directions: a forward-only walk found nothing whenever the user
        // opened the drawer on a paypal_funding chain's ASN (the `to`) side.
        while ($frontier !== [] && $depth < self::MAX_DEPTH) {
            $frontier = $this->expandFrontier($frontier, $visited, $nodes, $user);
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
     * @return list<int>
     */
    private function expandFrontier(array $frontier, array &$visited, array &$nodes, User $user): array
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

        $nextFrontier = [];
        foreach ($links as $link) {
            /** @var stdClass $link */
            $partnerId = $this->linkPartnerId($link, $visited);
            if ($partnerId === null || isset($visited[$partnerId])) {
                continue;
            }
            $visited[$partnerId] = true;
            $nextFrontier[] = $partnerId;
            $this->appendPartnerNode($nodes, $link, $partnerId, $user);
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

        // The partner is whichever side is not already on the frontier.
        return isset($visited[$fromId]) ? $toId : $fromId;
    }

    /**
     * @param  list<ChainTreeNode>  $nodes
     */
    private function appendPartnerNode(array &$nodes, stdClass $link, int $partnerId, User $user): void
    {
        $partnerRow = $this->fetchTransactionDisplayRow($partnerId, $user);
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
        );
    }

    private function confidenceTier(string $state, string $resolver, float $confidence): string
    {
        if ($state === ChainLinkState::Confirmed->value && $resolver === 'auto' && $confidence === 1.0) {
            return 'Deterministic';
        }
        if ($state === ChainLinkState::Confirmed->value) {
            return 'Confirmed';
        }

        return 'Candidate';
    }

    private function makeNode(stdClass $row, ?int $chainLinkId, string $kind, string $tier, User $user): ChainTreeNode
    {
        $accountName = $this->resolveAccountName(self::toInt($row->account_id ?? null), $user);
        $currency = self::toString($row->settled_currency ?? null);
        if ($currency === '') {
            $currency = self::toString($row->currency ?? null);
        }
        if ($currency === '') {
            $currency = 'EUR';
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
        $row = $this->db->connection()->table('transactions')
            ->leftJoin('counterparties', 'transactions.counterparty_id', '=', 'counterparties.id')
            ->where('transactions.id', $transactionId)
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
            ])
            ->first();

        return $row instanceof stdClass ? $row : null;
    }

    private function resolveAccountName(int $accountId, User $user): string
    {
        if ($accountId === 0) {
            return '';
        }
        $row = $this->db->connection()->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $user->id)
            ->first(['name']);

        if ($row === null) {
            return '';
        }

        return self::toString($row->name);
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
