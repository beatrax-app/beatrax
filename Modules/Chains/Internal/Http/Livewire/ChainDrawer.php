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
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * The chain drill-down side-drawer Livewire SFC (D-90 / D-92 / D-93,
 * UI-02). Mounts inside `/transactions/{id}` and renders when the
 * "View chain" button dispatches `chain-drawer:open` with the
 * transaction's id.
 *
 * Project's first Flux flyout — the Blade view `chain-drawer.blade.php`
 * uses `<flux:modal flyout position="right" class="md:w-2xl">` to
 * surface the chain tree as a vertical waterfall (top = the clicked
 * transaction; downward = funders). Flux owns the open/close/escape/
 * click-outside behaviour; this component owns the chain-tree data,
 * the fan-out pagination cursor (D-93), and the per-leg collapse
 * state (D-92).
 *
 * Per-leg state machine for the fan-out: `fanoutPage` starts at 0
 * (first 10 ICS charges visible); each `showMoreFanout()` call
 * increments the cursor; pagination is forward-only per UI-SPEC. The
 * `chain-node` partial computes which rows are visible from
 * `$fanoutPage` and renders the locked "Show 10 more · X of N" copy.
 *
 * Confirm + Reject chip actions delegate to the same `ConfirmChainLink`
 * + `RejectChainLink` Public action classes used by `/chains/review`
 * (D-86 — one Public action class powers both surfaces).
 *
 * Fan-out children: `ChainLinkQuery::forTransaction()` returns a flat
 * list of nodes in BFS order; `children: []` is the wave-3 default
 * (the Public DTO never populates the array because the drawer is the
 * sole consumer that needs it). This component reconstructs the
 * children-by-parent map by querying `chain_links` once per render
 * (single query, bounded by the visited nodes) and re-emits the node
 * list with `ChainTreeNode.children` populated for any node whose
 * outgoing `chain_links` carry kind=`ics_bulk_settle`. The Public DTO
 * stays immutable; the children are re-attached at the UI layer.
 */
final class ChainDrawer extends Component
{
    public ?int $transactionId = null;

    public int $fanoutPage = 0;

    public ?int $expandedFanoutId = null;

    /** @var array<int, bool> Per-leg collapse state (D-92 click-to-collapse). */
    public array $collapsedLegs = [];

    #[On('chain-drawer:open')]
    public function open(int $transactionId): void
    {
        $this->transactionId = $transactionId;
        $this->fanoutPage = 0;
        $this->expandedFanoutId = null;
        $this->collapsedLegs = [];
        // Open the Flux flyout from inside the listener so the
        // dispatch happens AFTER the component has set its
        // transactionId — and so the modal's stable `chain-drawer`
        // name is always the target. The earlier draft dispatched
        // modal-show from the trigger button with a name derived
        // from `tree.rootTransactionId`, which was `0` on first
        // click because the tree had not yet loaded; that race
        // produced the "View chain does nothing on first click"
        // bug. The browser event reaches Flux via Livewire's
        // dispatch bus the same way an inline `x-on:click` would.
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

    /**
     * Re-emit the chain tree with `ChainTreeNode.children` populated
     * for every `ics_bulk_settle` parent node — i.e. for every node
     * that has at least one outgoing `chain_links` row of kind=
     * `ics_bulk_settle`. The Public DTO never carries these
     * children directly (Wave 3 contract); the drawer rebuilds them
     * here from a single bounded query against `chain_links`.
     *
     * A node is treated as a fan-out parent when its kind is
     * `ics_bulk_settle` (the chain_link that reached this node was a
     * bulk-settle leg) AND it has outgoing same-user `ics_bulk_settle`
     * legs.
     */
    private function attachFanoutChildren(ChainTree $tree, DatabaseManager $db, CurrentUser $currentUser, SensitiveColumnCodec $codec, Session $session): ChainTree
    {
        if ($tree->nodes === []) {
            return $tree;
        }

        $user = $currentUser->user();
        $nodeIds = array_map(static fn (ChainTreeNode $n): int => $n->transactionId, $tree->nodes);

        // Single bounded query: pull every outgoing chain_link of
        // kind=ics_bulk_settle whose from_transaction_id is one of
        // the visited node ids. Filtered to state ∈ {confirmed,
        // candidate} so rejected legs are suppressed in the fan-out
        // exactly as they are in the parent walker.
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

        // Fan-out semantics (D-93, UI-SPEC § Chain drill-down drawer):
        // a node is a fan-out PARENT only when it has 2 or more
        // outgoing ics_bulk_settle chain_links. A single outgoing
        // ics_bulk_settle link is just a normal chain hop (settlement
        // covers 1 expense — uninteresting as a fan-out) and the
        // visited node stays in the flat waterfall list. With ≥2
        // outgoing legs, the parent renders the fan-out container
        // and the children are suppressed from the top-level
        // waterfall.
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

        // Hydrate every unique child transaction id once.
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

        // Build a set of transaction ids that are children of some
        // fan-out parent — these are rendered INSIDE the fan-out
        // container of their parent, not as flat waterfall legs of
        // their own. Per UI-SPEC § Chain drill-down drawer: bulk-
        // settle children render as a nested list under the
        // settlement node, never as duplicate top-level waterfall
        // cards.
        $childrenOfFanout = [];
        foreach ($childTxIdsByParent as $ids) {
            foreach ($ids as $id) {
                $childrenOfFanout[$id] = true;
            }
        }

        // Re-emit the nodes list with children attached on every node
        // that has outgoing ics_bulk_settle legs (including the root
        // node when the user opened the drawer directly on a bulk-
        // settle transfer leg). Skip nodes that are themselves
        // fan-out children — they live inside their parent's fan-out
        // container.
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
        $currency = $settledCurrency !== ''
            ? $settledCurrency
            : ($nativeCurrency !== '' ? $nativeCurrency : 'EUR');

        $amountMinor = self::toInt($row->settled_amount_minor ?? $row->amount_minor ?? null);

        $bookedAtRaw = self::toString($row->booked_at ?? null);
        $bookedAt = $bookedAtRaw !== ''
            ? CarbonImmutable::parse($bookedAtRaw)
            : CarbonImmutable::parse('1970-01-01 00:00:00');

        $storedCounterpartyName = self::toString($row->counterparty_name ?? null);
        // D-06 (14.1-10) read-side decrypt — pass-through no-op when
        // encryption is not enabled for this user.
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

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
