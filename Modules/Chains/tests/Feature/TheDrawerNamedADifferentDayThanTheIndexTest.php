<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Chains\Internal\ChainTreeWalker;
use Modules\Chains\Internal\Http\Livewire\ChainsIndex;
use Modules\Chains\Internal\Presentation\SettlementGroup;
use Modules\Chains\Public\Dto\ChainTree;
use Modules\Chains\Public\Dto\ChainTreeNode;
use Modules\Chains\Public\Http\Livewire\ChainDrawer;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ledger\Models\Transaction;

// /chains reads posted_at and the drawer read booked_at, so opening a card
// moved every ICS charge's date by the days the issuer took to book it. Only
// the ICS reader means the two columns differently -- every other adapter
// writes booked_at as posted_at plus a fixture time -- so the real card
// statement is the only fixture that can show it.
/**
 * @link ../fixtures/scenario-1/scenario-1.md
 */
beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');

    /** @var array{user: User} $seed */
    $seed = $this->seedFixtureUserAndAccount();
    $this->user = $seed['user'];
    $this->actingAs($this->user);

    $fixtures = base_path('Modules/Chains/tests/fixtures/scenario-1');

    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $importer->runAndConfirm($fixtures.'/ics-statement.pdf', 'ics-pdf', $this->user);
    $importer->runAndConfirm($fixtures.'/asn-camt053.xml', 'camt053', $this->user);

    /** @var Transaction $settlement */
    $settlement = Transaction::query()
        ->where('user_id', $this->user->id)
        ->where('source_format', 'camt053')
        ->sole();
    $this->settlement = $settlement;
});

// Two builders answer with a node and they have to agree: ChainTreeWalker
// makes every node of the tree, and ChainDrawer::makeChildNode remakes the
// fan-out children it collapses under their settlement.
it('gives every node the walker builds the day the transaction is listed and sorted by', function (): void {
    /** @var ChainTreeWalker $walker */
    $walker = $this->app->make(ChainTreeWalker::class);

    expect(daysByTransaction($walker->walk($this->settlement->id, $this->user)->nodes))
        ->toBe(postedDaysFromTheLedger($this->user));
});

it('gives every node the drawer builds the same day, fan-out children included', function (): void {
    $tree = Livewire::test(ChainDrawer::class)
        ->call('open', $this->settlement->id)
        ->viewData('tree');

    expect($tree)->toBeInstanceOf(ChainTree::class);
    expect(daysByTransaction($tree->nodes))->toBe(postedDaysFromTheLedger($this->user));
});

// The assertion above is only worth making because these two columns disagree
// on this fixture: every hand-written fixture in the tree writes booked_at as
// posted_at plus a time, and under that shape reading either column looks
// identical. The drawer prints the day of its ROOT node -- the fan-out children
// under a settlement carry a name and an amount and no date at all.
it('reads a fixture where the two days genuinely disagree, and prints the root node s', function (): void {
    /** @var Transaction $spotify */
    $spotify = Transaction::query()
        ->where('user_id', $this->user->id)
        ->where('counterparty_name', 'like', 'SPOTIFY%')
        ->sole();

    expect($spotify->posted_at->toDateString())->toBe('2026-04-15');
    expect($spotify->booked_at->toDateString())->toBe('2026-04-17');

    $charges = Transaction::query()
        ->where('user_id', $this->user->id)
        ->where('source_format', 'ics-pdf')
        ->get()
        ->filter(fn (Transaction $tx): bool => $tx->posted_at->toDateString() !== $tx->booked_at->toDateString());

    expect($charges)->toHaveCount(23);

    $component = Livewire::test(ChainDrawer::class)->call('open', $this->settlement->id);

    /** @var ChainTree $tree */
    $tree = $component->viewData('tree');
    $component->assertSee($tree->nodes[0]->postedAt->translatedFormat('d M Y'));
});

it('names the same day for a transaction that both the index and the drawer draw', function (): void {
    /** @var list<SettlementGroup> $settlements */
    $settlements = Livewire::test(ChainsIndex::class)->viewData('settlements');

    $onTheIndex = [];
    foreach ($settlements as $group) {
        $onTheIndex[$group->transactionId] = $group->postedAt->toDateString();
        foreach ($group->legs as $leg) {
            $onTheIndex[$leg->transactionId] = $leg->postedAt->toDateString();
        }
    }

    $tree = Livewire::test(ChainDrawer::class)
        ->call('open', $this->settlement->id)
        ->viewData('tree');

    $shared = 0;
    foreach (drawerNodesInOrder($tree->nodes) as $node) {
        if (! isset($onTheIndex[$node->transactionId])) {
            continue;
        }
        $shared++;
        expect($node->postedAt->toDateString())->toBe($onTheIndex[$node->transactionId]);
    }

    expect($shared)->toBeGreaterThan(0);
});

/**
 * @param  list<ChainTreeNode>  $nodes
 * @return array<int, string> the day each node names, keyed by transaction id
 */
function daysByTransaction(array $nodes): array
{
    $days = [];
    foreach (drawerNodesInOrder($nodes) as $node) {
        $days[$node->transactionId] = $node->postedAt->toDateString();
    }
    ksort($days);

    return $days;
}

/** @return array<int, string> */
function postedDaysFromTheLedger(User $user): array
{
    $days = Transaction::query()
        ->where('user_id', $user->id)
        ->get()
        ->mapWithKeys(fn (Transaction $tx): array => [$tx->id => $tx->posted_at->toDateString()])
        ->all();
    ksort($days);

    return $days;
}

/**
 * @param  list<ChainTreeNode>  $nodes
 * @return list<ChainTreeNode>
 */
function drawerNodesInOrder(array $nodes): array
{
    $flat = [];
    foreach ($nodes as $node) {
        $flat[] = $node;
        foreach (drawerNodesInOrder($node->children) as $child) {
            $flat[] = $child;
        }
    }

    return $flat;
}
