<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;

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

function thresholdRowFor(User $user, int $categoryId): ?object
{
    $fold = app(CarryoverQuery::class)->forUserAndPeriod($user, app(PeriodQuery::class)->current());

    return $fold['rows'][$categoryId] ?? null;
}

it('reports the default threshold of 90 for an envelope with no explicit threshold', function (): void {
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

    $this->assertDatabaseMissing('envelope_settings', [
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
    ]);
    expect($component->get('thresholdErrors')[$this->groceries->id] ?? null)->not->toBeNull();
    expect(thresholdRowFor($this->user, $this->groceries->id)->notifyThresholdPercent)->toBe(90);
});

it('rejects an out-of-range high value (999) and leaves an existing stored value unchanged', function (): void {
    Livewire::test(BudgetsPage::class)
        ->set("thresholdInputs.{$this->groceries->id}", '80')
        ->call('setNotifyThreshold', $this->groceries->id)
        ->assertHasNoErrors();

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
    Livewire::test(BudgetsPage::class)
        ->set("thresholdInputs.{$this->groceries->id}", '60')
        ->call('setNotifyThreshold', $this->groceries->id)
        ->assertHasNoErrors();

    // A fresh, activated user sharing the same global category.
    $userB = User::create([
        'username' => 'thresh-b-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    DB::table('users')->where('id', $userB->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonths(3)->startOfMonth(),
    ]);

    expect(thresholdRowFor($this->user, $this->groceries->id)->notifyThresholdPercent)->toBe(60);
    expect(thresholdRowFor($userB, $this->groceries->id)->notifyThresholdPercent)->toBe(90);
});

it('rejects an out-of-range threshold at the writer boundary, independent of the component guard', function (): void {
    $writer = app(EnvelopeWriter::class);

    expect(fn () => $writer->setNotifyThreshold($this->user, $this->groceries->id, 250))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $writer->setNotifyThreshold($this->user, $this->groceries->id, 0))
        ->toThrow(InvalidArgumentException::class);

    $this->assertDatabaseMissing('envelope_settings', [
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
    ]);
});
