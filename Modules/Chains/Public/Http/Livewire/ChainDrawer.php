<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Chains\Internal\Exceptions\ChainLinkRequiresConcretePartnerException;
use Modules\Chains\Internal\Presentation\CounterpartyDisplay;
use Modules\Chains\Public\Actions\ConfirmChainLink;
use Modules\Chains\Public\Actions\RejectChainLink;
use Modules\Chains\Public\Dto\ChainTree;
use Modules\Chains\Public\Dto\ChainTreeNode;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Chains\Public\Enums\ConfidenceTier;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ChainDrawer extends Component
{
    use CoercesScalars;

    public ?int $transactionId = null;

    public int $fanoutPage = 0;

    // Set when a chip acts on a link the other tab already decided; cleared
    // before every subsequent chip and on re-open.
    public ?string $actionError = null;

    #[On('chain-drawer:open')]
    public function open(int $transactionId): void
    {
        $this->transactionId = $transactionId;
        $this->fanoutPage = 0;
        $this->actionError = null;
        // Dispatch modal-show from here (not the trigger button) so it
        // fires after transactionId is set and always targets the stable
        // chain-drawer flyout name, avoiding the first-click no-op a
        // tree.rootTransactionId-derived name produced before the tree loaded.
        $this->dispatch('modal-show', name: 'chain-drawer');
    }

    // Both chips carry the two-tab case /chains/review already answers: the
    // link was decided on another screen between this render and this click,
    // and the shared Public action answers a gone row by throwing. The drawer
    // says so and repaints rather than handing the browser a 404.
    public function confirm(
        int|string $chainLinkId,
        CurrentUser $currentUser,
        ConfirmChainLink $confirm,
    ): void {
        $this->actionError = null;
        try {
            $confirm(DerivedRowId::fromWire($chainLinkId), $currentUser->user());
        } catch (ChainLinkRequiresConcretePartnerException) {
            $this->actionError = Lang::get('chains::review.errors.confirm_hint');
        } catch (NotFoundHttpException) {
            $this->actionError = Lang::get('core::errors.no_longer_here');
        }
    }

    public function reject(
        int|string $chainLinkId,
        CurrentUser $currentUser,
        RejectChainLink $reject,
    ): void {
        $this->actionError = null;
        try {
            $reject(DerivedRowId::fromWire($chainLinkId), $currentUser->user());
        } catch (ChainLinkRequiresConcretePartnerException) {
            $this->actionError = Lang::get('chains::review.errors.reject_hint');
        } catch (NotFoundHttpException) {
            $this->actionError = Lang::get('core::errors.no_longer_here');
        }
    }

    public function showMoreFanout(): void
    {
        $this->fanoutPage++;
    }

    public function render(
        CurrentUser $currentUser,
        ChainLinkQuery $query,
        DatabaseManager $db,
        ViewFactory $views,
        SensitiveColumnCodec $codec,
        Session $session,
        BaseCurrency $baseCurrency,
    ): View {
        if ($this->transactionId === null) {
            return $views->make('chains::livewire.chain-drawer', [
                'tree' => null,
                'fanoutPage' => $this->fanoutPage,
                'actionError' => $this->actionError,
            ]);
        }

        $tree = $query->forTransaction($this->transactionId, $currentUser->user());
        $tree = $this->attachFanoutChildren($tree, $db, $currentUser, $codec, $session, $baseCurrency->code());

        return $views->make('chains::livewire.chain-drawer', [
            'tree' => $tree,
            'fanoutPage' => $this->fanoutPage,
            'actionError' => $this->actionError,
        ]);
    }

    // ChainTreeNode.children always arrives empty from the Public DTO; the
    // fan-out shape is rebuilt here, in one bounded query.
    private function attachFanoutChildren(ChainTree $tree, DatabaseManager $db, CurrentUser $currentUser, SensitiveColumnCodec $codec, Session $session, string $baseCurrency): ChainTree
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
            $this->loadChildNodes($childTxIdsByParent, $db, $user, $codec, $session, $baseCurrency, $this->accountNames($db, $user)),
        );
    }

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
            // The link id is derived from the pair it joins, so it sorts in
            // hash order; the settled leg's own id is what puts the fan-out
            // children in the order the statement listed them.
            ->orderBy('to_transaction_id')
            ->get();

        /** @var array<int, list<int>> $rawByParent */
        $rawByParent = [];
        foreach ($links as $link) {
            /** @var stdClass $link */
            if ($link->to_transaction_id !== null) {
                $rawByParent[self::toInt($link->from_transaction_id)][] = self::toInt($link->to_transaction_id);
            }
        }

        // A single outgoing link is an ordinary chain hop and stays in the flat
        // waterfall, so the drawer never renders a fan-out container of one.
        return array_filter($rawByParent, static fn (array $childIds): bool => count($childIds) >= 2);
    }

    /**
     * @param  array<int, list<int>>  $childTxIdsByParent
     * @param  array<int, string>  $accountNames
     * @return array<int, ChainTreeNode>
     */
    private function loadChildNodes(array $childTxIdsByParent, DatabaseManager $db, User $user, SensitiveColumnCodec $codec, Session $session, string $baseCurrency, array $accountNames): array
    {
        $allChildIds = [];
        foreach ($childTxIdsByParent as $ids) {
            foreach ($ids as $id) {
                $allChildIds[$id] = true;
            }
        }

        $childRows = $db->connection()->table('transactions')
            ->leftJoin('counterparties', 'transactions.counterparty_id', '=', 'counterparties.id')
            ->where('transactions.user_id', $user->id)
            ->whereIn('transactions.id', array_keys($allChildIds))
            ->get([
                'transactions.id', 'transactions.counterparty_name',
                'transactions.amount_minor', 'transactions.currency',
                'transactions.settled_amount_minor', 'transactions.settled_currency',
                'transactions.posted_at', 'transactions.account_id',
                CounterpartyDisplay::SLUG_SELECT,
            ]);

        $childNodes = [];
        foreach ($childRows as $row) {
            /** @var stdClass $row */
            $childNodes[self::toInt($row->id)] = $this->makeChildNode($row, $user, $codec, $session, $baseCurrency, $accountNames);
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
                postedAt: $node->postedAt,
                accountName: $node->accountName,
                kind: $node->kind,
                confidenceTier: $node->confidenceTier,
                children: $children,
                counterpartySlug: $node->counterpartySlug,
            );
        }

        return new ChainTree(
            rootTransactionId: $tree->rootTransactionId,
            nodes: $rebuilt,
        );
    }

    /**
     * @return array<int, string> keyed by account id; an id absent here is one the
     *                            reader does not own, and its child shows no account name
     */
    private function accountNames(DatabaseManager $db, User $user): array
    {
        $names = [];
        foreach ($db->connection()->table('accounts')->where('user_id', $user->id)->get(['id', 'name']) as $row) {
            /** @var stdClass $row */
            $names[self::toInt($row->id)] = self::toString($row->name);
        }

        return $names;
    }

    // The names arrive as a map for the same reason ChainTreeWalker reads one:
    // MAX_DEPTH caps how deep the walk goes and nothing caps how wide, and a
    // settled ICS statement covers 50 to 300 charges. Asked per child, this
    // spent a query on each of them.
    /**
     * @param  array<int, string>  $accountNames
     */
    private function makeChildNode(stdClass $row, User $user, SensitiveColumnCodec $codec, Session $session, string $baseCurrency, array $accountNames): ChainTreeNode
    {
        $accountName = $accountNames[self::toInt($row->account_id ?? null)] ?? '';

        $settledCurrency = self::toString($row->settled_currency ?? null);
        $nativeCurrency = self::toString($row->currency ?? null);
        $currency = $settledCurrency !== '' ? $settledCurrency : $nativeCurrency;
        if ($currency === '') {
            $currency = $baseCurrency;
        }

        $amountMinor = self::toInt($row->settled_amount_minor ?? $row->amount_minor ?? null);

        $postedAtRaw = self::toString($row->posted_at ?? null);
        $postedAt = $postedAtRaw !== ''
            ? CarbonImmutable::parse($postedAtRaw)
            : CarbonImmutable::parse('1970-01-01 00:00:00');

        $storedCounterpartyName = self::toString($row->counterparty_name ?? null);
        $counterpartyName = $storedCounterpartyName === ''
            ? ''
            : $codec->decryptValue('transactions', 'counterparty_name', $storedCounterpartyName, $user->id, $session)['value'];

        $slug = self::toString($row->counterparty_slug ?? null);

        return new ChainTreeNode(
            transactionId: self::toInt($row->id),
            chainLinkId: null,
            counterpartyName: $counterpartyName,
            amount: Money::ofMinor($amountMinor, $currency),
            postedAt: $postedAt,
            accountName: $accountName,
            kind: 'ics_bulk_settle_child',
            confidenceTier: ConfidenceTier::Confirmed,
            children: [],
            counterpartySlug: $slug === '' ? null : $slug,
        );
    }
}
