<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;

/*
 * `users.envelope_activated_at` is written by the cutover migration and the
 * demo seeder, and by nothing in the running app. It is also absent from
 * MergeRulesRegistry. So a device that joined a household by pairing has it
 * null forever — and CarryoverQuery treated null as "never started" and
 * returned hardcoded zeros without reading envelope_assignments at all.
 *
 * Measured on a paired iPhone: 12 assignments totalling EUR 2.520,00, every one
 * of them for the month on screen, 11 of them synced from the Mac. The page
 * said "Nog niets toegewezen" and EUR 0,00 for all 24 categories, while the
 * sidebar badge — reading the same table — said "Budgetten 12".
 */

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

it('still offers a clickable zero grid for a user who genuinely has nothing', function (): void {
    $rows = Livewire::test(BudgetsPage::class)->viewData('rows');

    expect($rows)->toHaveCount(1)
        ->and($rows[$this->groceries->id]->assignedMinor)->toBe(0);
});
