<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Enums\OverspendMode;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Sync\Public\Events\EnvelopeSettingMutated;

// envelope_settings carries UNIQUE(user_id, category_id), so the second write
// to one envelope is an edit and never an insert — the branch that does it had
// never been run for either column it owns.
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'envsetting-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonthsNoOverflow(2)->startOfMonth(),
    ]);

    $this->category = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'envsetting-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
});

function envsettingRow(): ?object
{
    return DB::table('envelope_settings')
        ->where('user_id', test()->user->id)
        ->where('category_id', test()->category->id)
        ->first(['overspend_mode', 'threshold_percent']);
}

it('rewrites the overspend mode on the row it already has', function (): void {
    $writer = app(EnvelopeWriter::class);

    $writer->setOverspendMode($this->user, $this->category->id, OverspendMode::CarryNegative);
    expect(envsettingRow()->overspend_mode)->toBe(OverspendMode::CarryNegative->value);

    $writer->setOverspendMode($this->user, $this->category->id, OverspendMode::ReduceToBudget);

    expect(envsettingRow()->overspend_mode)->toBe(OverspendMode::ReduceToBudget->value)
        ->and(DB::table('envelope_settings')->where('user_id', $this->user->id)->count())->toBe(1);
});

// Clearing the box is a write, not a no-op: the stored percent goes to null and
// the fold answers with the default again, on the row that still holds the
// envelope's overspend mode.
it('clears a notify threshold the reader had set, and falls back to the default', function (): void {
    Livewire::test(BudgetsPage::class)
        ->set('thresholdInputs.'.$this->category->id, '80')
        ->call('setNotifyThreshold', $this->category->id)
        ->assertSet('thresholdErrors.'.$this->category->id, null);

    expect((int) envsettingRow()->threshold_percent)->toBe(80);

    Livewire::test(BudgetsPage::class)
        ->set('thresholdInputs.'.$this->category->id, '')
        ->call('setNotifyThreshold', $this->category->id)
        ->assertSet('thresholdInputs.'.$this->category->id, '');

    $row = envsettingRow();
    $fold = app(CarryoverQuery::class)->forUserAndPeriod($this->user, app(PeriodQuery::class)->current());

    expect($row->threshold_percent)->toBeNull()
        ->and($row->overspend_mode)->toBe(OverspendMode::ReduceToBudget->value)
        ->and($fold['rows'][$this->category->id]->notifyThresholdPercent)
        ->toBe(CarryoverQuery::DEFAULT_NOTIFY_THRESHOLD_PERCENT);
});

// Re-sending what is stored has to announce nothing at all: envelope_settings
// merges per field under last-write-wins, so an edit event carrying the value
// the peer already holds hands it a fresher timestamp for no new figure.
// Eloquent's save() on a clean model writes no UPDATE by itself, so the stored
// row cannot tell the two apart — the event is the only place it shows.
it('announces nothing when neither the mode nor the threshold moved', function (): void {
    $writer = app(EnvelopeWriter::class);
    $writer->setOverspendMode($this->user, $this->category->id, OverspendMode::CarryNegative);
    $writer->setNotifyThreshold($this->user, $this->category->id, 80);

    Event::fake([EnvelopeSettingMutated::class]);

    app(EnvelopeWriter::class)->setOverspendMode($this->user, $this->category->id, OverspendMode::CarryNegative);
    app(EnvelopeWriter::class)->setNotifyThreshold($this->user, $this->category->id, 80);

    Event::assertNotDispatched(EnvelopeSettingMutated::class);
});

it('announces the edit when one of them does move', function (): void {
    $writer = app(EnvelopeWriter::class);
    $writer->setOverspendMode($this->user, $this->category->id, OverspendMode::CarryNegative);

    Event::fake([EnvelopeSettingMutated::class]);

    app(EnvelopeWriter::class)->setOverspendMode($this->user, $this->category->id, OverspendMode::ReduceToBudget);

    Event::assertDispatched(
        EnvelopeSettingMutated::class,
        static fn (EnvelopeSettingMutated $event): bool => $event->mutationType === 'edit'
            && $event->dirtyFields === ['overspend_mode' => OverspendMode::ReduceToBudget->value],
    );
});
