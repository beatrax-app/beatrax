<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Recurring\Internal\Http\Livewire\RecurringReviewPage;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesTransition;

function rrbUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function rrbSeries(User $user, string $state, string $cluster, string $name = 'rrb-row'): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => $state,
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => $cluster,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    $this->user = rrbUser('rrb');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('flips all selected series to approved + writes per-row transitions + dispatches a single toast (bulk-approve)', function (): void {
    $ids = [];
    for ($i = 0; $i < 20; $i++) {
        $ids[] = rrbSeries($this->user, 'pending', 'rrb::pending::'.$i, 'row-'.$i)->id;
    }

    $component = Livewire::actingAs($this->user)
        ->test(RecurringReviewPage::class)
        ->set('selectedIds', $ids)
        ->call('bulkApprove');

    $approved = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('state', 'approved')
        ->count();
    expect($approved)->toBe(20);

    $transitions = RecurringSeriesTransition::query()
        ->where('user_id', $this->user->id)
        ->where('to_state', 'approved')
        ->count();
    expect($transitions)->toBe(20);

    expect($component->get('selectedIds'))->toBe([]);
    $component->assertDispatched('toast');
})->group('bulk-approve');

it('flips all selected series to rejected (bulk-reject)', function (): void {
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = rrbSeries($this->user, 'pending', 'rrb::reject::'.$i, 'row-'.$i)->id;
    }

    Livewire::actingAs($this->user)
        ->test(RecurringReviewPage::class)
        ->set('selectedIds', $ids)
        ->call('bulkReject');

    $rejected = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('state', 'rejected')
        ->count();
    expect($rejected)->toBe(5);
})->group('bulk-reject');

it('skips foreign-user ids silently — only the caller`s rows flip (bulk-approve-skips-foreign-user-ids)', function (): void {
    $mine = [];
    for ($i = 0; $i < 10; $i++) {
        $mine[] = rrbSeries($this->user, 'pending', 'rrb::mine::'.$i, 'mine-'.$i)->id;
    }
    $other = rrbUser('rrb-other');
    $foreign = [];
    for ($i = 0; $i < 5; $i++) {
        $foreign[] = rrbSeries($other, 'pending', 'rrb::other::'.$i, 'other-'.$i)->id;
    }

    Livewire::actingAs($this->user)
        ->test(RecurringReviewPage::class)
        ->set('selectedIds', array_merge($mine, $foreign))
        ->call('bulkApprove');

    $approvedMine = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('state', 'approved')
        ->count();
    expect($approvedMine)->toBe(10);

    $approvedForeign = RecurringSeries::query()
        ->where('user_id', $other->id)
        ->where('state', 'approved')
        ->count();
    expect($approvedForeign)->toBe(0);
})->group('bulk-approve-skips-foreign-user-ids');

it('coerces string ids from the wire payload before dispatching the action (bulk-approve-accepts-string-ids)', function (): void {
    // Real browser payload: HTML checkbox value="" attributes round-trip through
    // Livewire as strings, not ints, so the bulk handlers must coerce before
    // calling the int-typed Public Action. Regression for a TypeError seen live.
    $stringIds = [];
    for ($i = 0; $i < 3; $i++) {
        $stringIds[] = (string) rrbSeries($this->user, 'pending', 'rrb::str::'.$i, 'str-'.$i)->id;
    }

    Livewire::actingAs($this->user)
        ->test(RecurringReviewPage::class)
        ->set('selectedIds', $stringIds)
        ->call('bulkApprove');

    $approved = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('state', 'approved')
        ->count();
    expect($approved)->toBe(3);
})->group('bulk-approve-accepts-string-ids');

it('renders the sticky action bar markup only when selectedIds is non-empty (bulk-action-bar-renders-only-when-selection-non-empty)', function (): void {
    $series = rrbSeries($this->user, 'pending', 'rrb::bar', 'bar-row');

    $component = Livewire::actingAs($this->user)
        ->test(RecurringReviewPage::class);
    expect($component->html())->not->toContain('wire:click="bulkApprove"');

    $component->set('selectedIds', [$series->id]);
    expect($component->html())->toContain('wire:click="bulkApprove"');
    expect($component->html())->toContain('wire:click="bulkReject"');
})->group('bulk-action-bar-renders-only-when-selection-non-empty');

// Only NotFoundHttpException was caught, so the first row the state graph
// refused took the batch down: the rows before it were already written, the
// rows after it never were, and the reader was shown a 500 instead of a count.
it('applies the rest of a bulk batch when one selected row is in a state the graph refuses', function (): void {
    $first = rrbSeries($this->user, 'pending', 'rrb::mix::a', 'row-a');
    $rejected = rrbSeries($this->user, 'rejected', 'rrb::mix::b', 'row-b');
    $last = rrbSeries($this->user, 'pending', 'rrb::mix::c', 'row-c');

    $component = Livewire::actingAs($this->user)
        ->test(RecurringReviewPage::class)
        ->set('selectedIds', [$first->id, $rejected->id, $last->id])
        ->call('bulkApprove');

    expect(RecurringSeries::query()->whereIn('id', [$first->id, $last->id])->pluck('state')->all())
        ->toBe(['approved', 'approved']);
    expect(RecurringSeries::query()->find($rejected->id)->state)->toBe('rejected');
    $component->assertDispatched('toast');
});

it('drops the selection when the reader switches tab so a batch cannot mix states', function (): void {
    $pending = rrbSeries($this->user, 'pending', 'rrb::tab::a', 'row-a');

    $component = Livewire::actingAs($this->user)
        ->test(RecurringReviewPage::class)
        ->set('selectedIds', [$pending->id])
        ->call('setTab', 'rejected');

    expect($component->get('selectedIds'))->toBe([]);
});

// bulkUndo was named as the undo action of both bulk toasts and never existed
// as a method, so the toast promised a rollback nothing could perform.
it('names no undo action the component cannot perform', function (): void {
    $pending = rrbSeries($this->user, 'pending', 'rrb::undo::a', 'row-a');

    $component = Livewire::actingAs($this->user)
        ->test(RecurringReviewPage::class)
        ->set('selectedIds', [$pending->id])
        ->call('bulkApprove');

    foreach ($component->effects['dispatches'] ?? [] as $dispatch) {
        $undo = $dispatch['params']['undoAction'] ?? null;
        expect($undo === null || method_exists(RecurringReviewPage::class, (string) $undo))->toBeTrue();
    }
});
