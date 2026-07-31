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
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
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
        $childTxIdsByParent = $this->fanoutParents($tree, $db, $user);

        if ($childTxIdsByParent === []) {
            return $tree;
        }

        return $this->nestChildren(
            $tree,
            $childTxIdsByParent,
            $this->loadChildNodes($childTxIdsByParent, $db, $user, $codec, $session),
        );
    }

    // Confirmed and candidate only, so rejected legs stay suppressed exactly
    // as they are in the parent walker.
    /**
     * @return array<int, list<int>> child transaction ids per fan-out parent
     */
    private function fanoutParents(ChainTree $tree, DatabaseManager $db, User $user): array
    {
        $nodeIds = array_map(static fn (ChainTreeNode $n): int => $n->transactionId, $tree->nodes);

        $links = $db->connection()->table('chain_links')
            ->where('user_id', $user->id)
            ->where('kind', ChainLinkKind::IcsBulkSettle->value)
            ->whereIn('from_transaction_id', $nodeIds)
            ->whereIn('state', [ChainLinkState::Confirmed->value, ChainLinkState::Candidate->value])
            ->orderBy('id')
            ->get();

        /** @var array<int, list<int>> $rawByParent */
        $rawByParent = [];
        foreach ($links as $link) {
            /** @var stdClass $link */
            if ($link->to_transaction_id !== null) {
                $rawByParent[self::toInt($link->from_transaction_id)][] = self::toInt($link->to_transaction_id);
            }
        }

        // Two or more outgoing links make a fan-out parent. A single link is
        // an ordinary chain hop and stays in the flat waterfall list rather
        // than rendering a fan-out container of one.
        return array_filter($rawByParent, static fn (array $childIds): bool => count($childIds) >= 2);
    }

    /**
     * @param  array<int, list<int>>  $childTxIdsByParent
     * @return array<int, ChainTreeNode>
     */
    private function loadChildNodes(array $childTxIdsByParent, DatabaseManager $db, User $user, SensitiveColumnCodec $codec, Session $session): array
    {
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

        $childNodes = [];
        foreach ($childRows as $row) {
            /** @var stdClass $row */
            $childNodes[self::toInt($row->id)] = $this->makeChildNode($row, $db, $user, $codec, $session);
        }

        return $childNodes;
    }

    // A fan-out child renders inside its parent's container, so it is
    // skipped at the top level rather than rendering twice.
    /**
     * @param  array<int, list<int>>  $childTxIdsByParent
     * @param  array<int, ChainTreeNode>  $childNodes
     */
    private function nestChildren(ChainTree $tree, array $childTxIdsByParent, array $childNodes): ChainTree
    {
        $childrenOfFanout = [];
        foreach ($childTxIdsByParent as $ids) {
            foreach ($ids as $id) {
                $childrenOfFanout[$id] = true;
            }
        }

        $rebuilt = [];
        foreach ($tree->nodes as $node) {
            if (isset($childrenOfFanout[$node->transactionId])) {
                continue;
            }

            $children = [];
            foreach ($childTxIdsByParent[$node->transactionId] ?? [] as $childId) {
                if (isset($childNodes[$childId])) {
                    $children[] = $childNodes[$childId];
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
