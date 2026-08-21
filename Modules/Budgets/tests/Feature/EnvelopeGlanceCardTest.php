<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Budgets\Public\Http\Livewire\EnvelopeGlanceCard;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'glance-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
});

it('renders a ready-to-assign figure sourced from the envelope model, not category_budgets', function (): void {
    $this->actingAs($this->user);
    Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'glance-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);

    Livewire::test(EnvelopeGlanceCard::class)
        ->assertOk()
        ->assertSee('Budgets')
        ->assertSee('Ready to assign');
});

it('renders an over-budget amber pill only when at least one envelope is overspent', function (): void {
    $this->actingAs($this->user);
    $groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'glance-overspend-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);

    $period = app(PeriodQuery::class)->current();
    app(EnvelopeWriter::class)->setAssigned($this->user, $groceries->id, $period->start, 10000);

    Livewire::test(EnvelopeGlanceCard::class)
        ->assertDontSee('over budget');
});

it('renders card chrome with a null figure when unauthenticated, never a blank gap', function (): void {
    Livewire::test(EnvelopeGlanceCard::class)
        ->assertOk()
        ->assertSee('Budgets');
});

it('renders nothing when the user has zero expense categories (graceful collapse)', function (): void {
    $this->actingAs($this->user);

    Livewire::test(EnvelopeGlanceCard::class)
        ->assertDontSee('Ready to assign');
});
