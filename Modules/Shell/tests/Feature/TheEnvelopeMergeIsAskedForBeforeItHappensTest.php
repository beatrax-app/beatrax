<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);

    $db = app(DatabaseManager::class)->connection();
    $this->categoryId = $db->table('categories')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'Groceries',
        'slug' => 'groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $db->table('envelope_assignments')->insert([
        'user_id' => $this->user->id,
        'category_id' => $this->categoryId,
        'period_start' => '2026-08-01',
        'assigned_minor' => 40000,
        'currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

// Moving the day re-files every envelope row and ADDS the amounts wherever two
// old periods fold onto one new one. Setting the day back re-runs the merge on
// the summed rows rather than splitting them, so this was a one-way door with
// a plain Save button in front of it and nothing said so.
it('re-files no envelope row on the press that only asks the question', function (): void {
    Livewire::test(SettingsPage::class)
        ->set('periodStartDay', 25)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('confirmingPeriodMove', true)
        ->assertSet('saved', false);

    expect($this->user->fresh()->period_start_day)->toBe(1);

    $rows = app(DatabaseManager::class)->connection()
        ->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->pluck('period_start')
        ->all();

    expect($rows)->toBe(['2026-08-01']);
});

it('moves the period once the question is answered', function (): void {
    Livewire::test(SettingsPage::class)
        ->set('periodStartDay', 25)
        ->call('save')
        ->call('save')
        ->assertSet('confirmingPeriodMove', false)
        ->assertSet('saved', true);

    expect($this->user->fresh()->period_start_day)->toBe(25);
});

it('puts the day back where it was when the question is declined', function (): void {
    Livewire::test(SettingsPage::class)
        ->set('periodStartDay', 25)
        ->call('save')
        ->call('cancelPeriodMove')
        ->assertSet('confirmingPeriodMove', false)
        ->assertSet('periodStartDay', 1);

    expect($this->user->fresh()->period_start_day)->toBe(1);
});

it('asks nothing of a save that leaves the day alone', function (): void {
    Livewire::test(SettingsPage::class)
        ->set('defaultCurrencyView', 'original')
        ->call('save')
        ->assertSet('confirmingPeriodMove', false)
        ->assertSet('saved', true);
});
