<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Http\Livewire\ForecastPage;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;

uses(RefreshDatabase::class);

// The segmented control used to hard-code [30, 60, 90]; these pin it to the
// HORIZON_DAYS constant instead.

function fphUser(string $suffix = 'fph'): User
{
    return User::query()->create([
        'username' => $suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function fphAccount(int $userId, string $name = 'ASN'): void
{
    $hex = bin2hex(random_bytes(4));
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('accounts')->insert([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'fph-'.$hex,
        'kind' => 'bank',
        'iban' => 'NL00FPH'.strtoupper($hex),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 100000,
        'opening_balance_as_of_date' => '2026-05-01',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('renders a horizon button with wire:click="setHorizon(180)"', function (): void {
    $user = fphUser('fph-180');
    fphAccount($user->id);

    Livewire::actingAs($user)
        ->test(ForecastPage::class)
        ->assertSee('wire:click="setHorizon(180)"', false);
});

it('renders a horizon button with wire:click="setHorizon(365)"', function (): void {
    $user = fphUser('fph-365');
    fphAccount($user->id);

    Livewire::actingAs($user)
        ->test(ForecastPage::class)
        ->assertSee('wire:click="setHorizon(365)"', false);
});

it('renders all five HORIZON_DAYS options in the segmented control', function (): void {
    $user = fphUser('fph-all');
    fphAccount($user->id);

    $component = Livewire::actingAs($user)->test(ForecastPage::class);

    foreach (ProjectForecastJob::HORIZON_DAYS as $days) {
        $component->assertSee("setHorizon({$days})", false);
    }
});

it('HORIZON_DAYS constant contains exactly [30, 60, 90, 180, 365]', function (): void {
    expect(ProjectForecastJob::HORIZON_DAYS)->toBe([30, 60, 90, 180, 365]);
});
