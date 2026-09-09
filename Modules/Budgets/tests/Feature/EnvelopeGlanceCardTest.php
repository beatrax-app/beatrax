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

it('renders a ready-to-assign figure sourced from the envelope fold', function (): void {
    $this->actingAs($this->user);
    Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'glance-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);

    Livewire::test(EnvelopeGlanceCard::class)
        ->assertOk()
        ->assertSee('Budgets')
        ->assertSee('Ready to assign');
});

// The figure is an arithmetic result the card never shows the working for, and
// this is the screen a reader opens first. /budgets explained it from the day
// the affordance shipped; here the same three words stood alone.
it('offers the reader the same panel /budgets offers beside those three words', function (): void {
    $this->actingAs($this->user);
    Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'glance-help-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);

    $html = Livewire::test(EnvelopeGlanceCard::class)->html();

    expect($html)->toContain('popovertarget="help-tip-budgets-glance-ready"')
        ->and($html)->toContain('aria-label="About Ready to assign"')
        ->and($html)->toContain('Money that has arrived and has no envelope yet');

    // A div popover inside a p closes the paragraph in the parser, which would
    // put the figure outside the block that carries the label's type.
    expect($html)->toMatch('~<div class="text-xs[^"]*">Ready to assign&nbsp;<button~');
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
