<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;

/*
 * Phase 18 Plan 02 (Option B redirect): the D-20 per-budget over-budget
 * notify threshold lives on the LIVE envelope model
 * (`envelope_settings.threshold_percent`), surfaced on
 * `EnvelopeRow::$notifyThresholdPercent` via `CarryoverQuery` — NOT the
 * write-dead `category_budgets` table. Plan 18-07's nudge job reads that
 * Public seam.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'thresh-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    // Envelope-activation genesis so CarryoverQuery folds real rows.
    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonths(3)->startOfMonth(),
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'thresh-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
});

/** The current-period EnvelopeRow for a category, via the live read seam. */
function thresholdRowFor(User $user, int $categoryId): ?object
{
    $fold = app(CarryoverQuery::class)->forUserAndPeriod($user, app(PeriodQuery::class)->current());

    return $fold['rows'][$categoryId] ?? null;
}

it('reports the D-20 default of 90 for an envelope with no explicit threshold', function (): void {
    $row = thresholdRowFor($this->user, $this->groceries->id);

    expect($row)->not->toBeNull();
    expect($row->notifyThresholdPercent)->toBe(90);
});

it('persists a threshold saved through the component and reports it on the read seam', function (): void {
    Livewire::test(BudgetsPage::class)
        ->set("thresholdInputs.{$this->groceries->id}", '75')
        ->call('setNotifyThreshold', $this->groceries->id)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('envelope_settings', [
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'threshold_percent' => 75,
    ]);

    expect(thresholdRowFor($this->user, $this->groceries->id)->notifyThresholdPercent)->toBe(75);
});

it('rejects an out-of-range low value (0) and leaves the stored value unchanged', function (): void {
    $component = Livewire::test(BudgetsPage::class)
        ->set("thresholdInputs.{$this->groceries->id}", '0')
        ->call('setNotifyThreshold', $this->groceries->id);

    // No settings row written; the read seam still reports the default.
    $this->assertDatabaseMissing('envelope_settings', [
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
    ]);
    expect($component->get('thresholdErrors')[$this->groceries->id] ?? null)->not->toBeNull();
    expect(thresholdRowFor($this->user, $this->groceries->id)->notifyThresholdPercent)->toBe(90);
});

it('rejects an out-of-range high value (999) and leaves an existing stored value unchanged', function (): void {
    // Seed a valid explicit threshold first.
    Livewire::test(BudgetsPage::class)
        ->set("thresholdInputs.{$this->groceries->id}", '80')
        ->call('setNotifyThreshold', $this->groceries->id)
        ->assertHasNoErrors();

    // Now attempt an out-of-range save — it must be rejected and NOT overwrite 80.
    Livewire::test(BudgetsPage::class)
        ->set("thresholdInputs.{$this->groceries->id}", '999')
        ->call('setNotifyThreshold', $this->groceries->id);

    $this->assertDatabaseHas('envelope_settings', [
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'threshold_percent' => 80,
    ]);
    expect(thresholdRowFor($this->user, $this->groceries->id)->notifyThresholdPercent)->toBe(80);
});

it('does not leak one user\'s threshold to another user', function (): void {
    // User A sets an explicit threshold.
    Livewire::test(BudgetsPage::class)
        ->set("thresholdInputs.{$this->groceries->id}", '60')
        ->call('setNotifyThreshold', $this->groceries->id)
        ->assertHasNoErrors();

    // User B — a fresh, activated user sharing the same global category.
    $userB = User::create([
        'username' => 'thresh-b-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    DB::table('users')->where('id', $userB->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonths(3)->startOfMonth(),
    ]);

    // A reports 60; B reports the default 90 — no cross-user bleed.
    expect(thresholdRowFor($this->user, $this->groceries->id)->notifyThresholdPercent)->toBe(60);
    expect(thresholdRowFor($userB, $this->groceries->id)->notifyThresholdPercent)->toBe(90);
});
