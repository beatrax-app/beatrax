<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Chains\Public\Actions\ConfirmChainLink;
use Modules\Chains\Public\Actions\RejectChainLink;
use Modules\Chains\Public\Dto\ChainTree;
use Modules\Chains\Public\Dto\ChainTreeNode;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * @link ../../../../../.docs/features/chains/architecture.md
 */
final class ChainDrawer extends Component
{
    use CoercesScalars;

    public ?int $transactionId = null;

    public int $fanoutPage = 0;

    public ?int $expandedFanoutId = null;

    /** @var array<int, bool> Per-leg click-to-collapse state. */
    public array $collapsedLegs = [];

    #[On('chain-drawer:open')]
    public function open(int $transactionId): void
    {
        $this->transactionId = $transactionId;
        $this->fanoutPage = 0;
        $this->expandedFanoutId = null;
        $this->collapsedLegs = [];
        // Dispatch modal-show from here (not the trigger button) so it
        // fires after transactionId is set and always targets the stable
        // chain-drawer flyout name, avoiding the first-click no-op a
        // tree.rootTransactionId-derived name produced before the tree loaded.
        $this->dispatch('modal-show', name: 'chain-drawer');
    }

    public function confirm(
        int $chainLinkId,
        CurrentUser $currentUser,
        ConfirmChainLink $confirm,
    ): void {
        $confirm($chainLinkId, $currentUser->user());
    }

    public function reject(
        int $chainLinkId,
        CurrentUser $currentUser,
        RejectChainLink $reject,
    ): void {
        $reject($chainLinkId, $currentUser->user());
    }

    public function showMoreFanout(): void
    {
        $this->fanoutPage++;
    }

    public function toggleLeg(int $chainLinkId): void
    {
        $this->collapsedLegs[$chainLinkId] = ! ($this->collapsedLegs[$chainLinkId] ?? false);
    }

    public function render(
        CurrentUser $currentUser,
        ChainLinkQuery $query,
        DatabaseManager $db,
        ViewFactory $views,
        SensitiveColumnCodec $codec,
        Session $session,
    ): View {
        if ($this->transactionId === null) {
            return $views->make('chains::livewire.chain-drawer', [
                'tree' => null,
                'fanoutPage' => $this->fanoutPage,
            ]);
        }

        $tree = $query->forTransaction($this->transactionId, $currentUser->user());
        $tree = $this->attachFanoutChildren($tree, $db, $currentUser, $codec, $session);

        return $views->make('chains::livewire.chain-drawer', [
            'tree' => $tree,
            'fanoutPage' => $this->fanoutPage,
        ]);
    }

    // Rebuilds ChainTreeNode.children (always empty on the Public DTO) via
    // one bounded query against chain_links, for every node with 2+
    // outgoing ics_bulk_settle links. See architecture.md for the fan-out
    // parent/child rules this implements.
    private function attachFanoutChildren(ChainTree $tree, DatabaseManager $db, CurrentUser $currentUser, SensitiveColumnCodec $codec, Session $session): ChainTree
    {
        if ($tree->nodes === []) {
            return $tree;
        }

        $user = $currentUser->user();
        $nodeIds = array_map(static fn (ChainTreeNode $n): int => $n->transactionId, $tree->nodes);

        // Pulls every outgoing ics_bulk_settle chain_link for the visited
        // node ids, filtered to confirmed/candidate so rejected legs stay
        // suppressed exactly as in the parent walker.
        $links = $db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->where('kind', 'ics_bulk_settle')
            ->whereIn('from_transaction_id', $nodeIds)
            ->whereIn('state', ['confirmed', 'candidate'])
            ->orderBy('id')
            ->get();

        /** @var array<int, list<int>> $rawByParent */
        $rawByParent = [];
        foreach ($links as $link) {
            /** @var stdClass $link */
            if ($link->to_transaction_id === null) {
                continue;
            }
            $parentId = self::toInt($link->from_transaction_id);
            $rawByParent[$parentId][] = self::toInt($link->to_transaction_id);
        }

        // A node is a fan-out parent only with 2+ outgoing ics_bulk_settle
        // links; a single link is just a normal chain hop and stays in the
        // flat waterfall list instead of rendering a fan-out container.
        /** @var array<int, list<int>> $childTxIdsByParent */
        $childTxIdsByParent = [];
        foreach ($rawByParent as $parentId => $childIds) {
            if (count($childIds) >= 2) {
                $childTxIdsByParent[$parentId] = $childIds;
            }
        }

        if ($childTxIdsByParent === []) {
            return $tree;
        }

        $allChildIds = [];
        foreach ($childTxIdsByParent as $ids) {
            foreach ($ids as $id) {
                $allChildIds[$id] = true;
            }
        }
        $childRows = $db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('id', array_keys($allChildIds))
            ->get([
                'id', 'counterparty_name', 'amount_minor', 'currency',
                'settled_amount_minor', 'settled_currency', 'booked_at',
                'account_id',
            ]);

        /** @var array<int, ChainTreeNode> $childNodes */
        $childNodes = [];
        foreach ($childRows as $row) {
            /** @var stdClass $row */
            $childId = self::toInt($row->id);
            $childNodes[$childId] = $this->makeChildNode($row, $db, $user, $codec, $session);
        }

        // Children of a fan-out parent render nested under it, never as
        // duplicate top-level waterfall cards of their own.
        $childrenOfFanout = [];
        foreach ($childTxIdsByParent as $ids) {
            foreach ($ids as $id) {
                $childrenOfFanout[$id] = true;
            }
        }

        // Skip nodes that are themselves fan-out children — they render
        // inside their parent's fan-out container instead.
        $rebuilt = [];
        foreach ($tree->nodes as $node) {
            if (isset($childrenOfFanout[$node->transactionId])) {
                continue;
            }

            $children = [];
            if (isset($childTxIdsByParent[$node->transactionId])) {
                foreach ($childTxIdsByParent[$node->transactionId] as $childId) {
                    if (isset($childNodes[$childId])) {
                        $children[] = $childNodes[$childId];
                    }
                }
            }

            $rebuilt[] = new ChainTreeNode(
                transactionId: $node->transactionId,
                chainLinkId: $node->chainLinkId,
                counterpartyName: $node->counterpartyName,
                amount: $node->amount,
                bookedAt: $node->bookedAt,
                accountName: $node->accountName,
                kind: $node->kind,
                confidenceTier: $node->confidenceTier,
                children: $children,
            );
        }

        return new ChainTree(
            rootTransactionId: $tree->rootTransactionId,
            nodes: $rebuilt,
        );
    }

    private function makeChildNode(stdClass $row, DatabaseManager $db, User $user, SensitiveColumnCodec $codec, Session $session): ChainTreeNode
    {
        $accountId = self::toInt($row->account_id ?? null);
        $accountName = '';
        if ($accountId !== 0) {
            $acc = $db->connection()->table('accounts')
                ->where('id', $accountId)
                ->where('user_id', $user->id)
                ->first(['name']);
            if ($acc !== null) {
                $accountName = self::toString($acc->name ?? null);
            }
        }

        $settledCurrency = self::toString($row->settled_currency ?? null);
        $nativeCurrency = self::toString($row->currency ?? null);
        // Settled currency wins, then the native one; EUR is the last resort
        // for a row that carries neither rather than rendering an empty code.
        $currency = $settledCurrency !== '' ? $settledCurrency : $nativeCurrency;
        if ($currency === '') {
            $currency = 'EUR';
        }

        $amountMinor = self::toInt($row->settled_amount_minor ?? $row->amount_minor ?? null);

        $bookedAtRaw = self::toString($row->booked_at ?? null);
        $bookedAt = $bookedAtRaw !== ''
            ? CarbonImmutable::parse($bookedAtRaw)
            : CarbonImmutable::parse('1970-01-01 00:00:00');

        $storedCounterpartyName = self::toString($row->counterparty_name ?? null);
        // Read-side decrypt; a pass-through no-op when encryption is not
        // enabled for this user.
        $counterpartyName = $storedCounterpartyName === ''
            ? ''
            : $codec->decryptValue('transactions', 'counterparty_name', $storedCounterpartyName, $user->id, $session)['value'];

        return new ChainTreeNode(
            transactionId: self::toInt($row->id),
            chainLinkId: null,
            counterpartyName: $counterpartyName,
            amount: Money::ofMinor($amountMinor, $currency),
            bookedAt: $bookedAt,
            accountName: $accountName,
            kind: 'ics_bulk_settle_child',
            confidenceTier: 'Confirmed',
            children: [],
        );
    }
}
