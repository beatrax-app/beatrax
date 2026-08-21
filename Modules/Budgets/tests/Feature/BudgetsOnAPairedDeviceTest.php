<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;

// `users.envelope_activated_at` is written only by the cutover migration and
// the demo seeder, and is absent from MergeRulesRegistry — so a device that
// joined a household by pairing has it null forever, and CarryoverQuery used
// to read null as "never started" and return zeros over real assignments.
beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-19 12:00:00');

    $this->user = User::create([
        'username' => 'paired-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    // Exactly the paired-device shape: assignments present, anchor never set.
    DB::table('users')->where('id', $this->user->id)->update(['envelope_activated_at' => null]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Wonen', 'slug' => 'paired-wonen-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('shows an assignment that arrived by sync, with no activation stamp', function (): void {
    DB::table('envelope_assignments')->insert([
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'period_start' => '2026-08-01',
        'assigned_minor' => 25000,
        'currency' => 'EUR',
        'created_at' => '2026-08-19 10:00:00',
        'updated_at' => '2026-08-19 10:00:00',
    ]);

    $rows = Livewire::test(BudgetsPage::class)->viewData('rows');

    expect($rows)->toHaveKey($this->groceries->id)
        ->and($rows[$this->groceries->id]->assignedMinor)->toBe(25000);
});

it('does not report zero over money that is really there', function (): void {
    DB::table('envelope_assignments')->insert([
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'period_start' => '2026-08-01',
        'assigned_minor' => 25000,
        'currency' => 'EUR',
        'created_at' => '2026-08-19 10:00:00',
        'updated_at' => '2026-08-19 10:00:00',
    ]);

    Livewire::test(BudgetsPage::class)
        ->assertOk()
        ->assertDontSee('Nothing assigned yet');
});

it('can navigate back to a month that only exists because it synced in', function (): void {
    DB::table('envelope_assignments')->insert([
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'period_start' => '2026-06-01',
        'assigned_minor' => 25000,
        'currency' => 'EUR',
        'created_at' => '2026-08-19 10:00:00',
        'updated_at' => '2026-08-19 10:00:00',
    ]);

    $component = Livewire::test(BudgetsPage::class);

    expect($component->viewData('canGoPrevious'))->toBeTrue();

    $component->call('prevPeriod');

    expect($component->viewData('period')->start->toDateString())->toBe('2026-07-01');
});

it('still offers a clickable zero grid for a user who genuinely has nothing', function (): void {
    $rows = Livewire::test(BudgetsPage::class)->viewData('rows');

    expect($rows)->toHaveCount(1)
        ->and($rows[$this->groceries->id]->assignedMinor)->toBe(0);
});
