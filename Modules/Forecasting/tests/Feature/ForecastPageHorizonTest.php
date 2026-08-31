<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Http\Livewire\ForecastPage;
use Modules\Forecasting\Public\Enums\ForecastHorizon;

uses(RefreshDatabase::class);

// The segmented control used to hard-code [30, 60, 90]; these pin it to the
// ForecastHorizon enum instead.

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

it('renders every ForecastHorizon option in the segmented control', function (): void {
    $user = fphUser('fph-all');
    fphAccount($user->id);

    $component = Livewire::actingAs($user)->test(ForecastPage::class);

    foreach (ForecastHorizon::days() as $days) {
        $component->assertSee("setHorizon({$days})", false);
    }
});

it('ForecastHorizon names exactly [30, 60, 90, 180, 365]', function (): void {
    expect(ForecastHorizon::days())->toBe([30, 60, 90, 180, 365]);
});

it('falls back to the opening horizon when the address bar names one the rail does not offer', function (): void {
    $user = fphUser('fph-tampered');
    fphAccount((int) $user->id);
    test()->actingAs($user);

    // 999 is not merely absent from the segmented control — it reached
    // ForecastQuery and drew a 999-day projection with no chip lit, so the
    // reader had no way back to a horizon the rail does offer.
    Livewire::withQueryParams(['horizon' => 999])
        ->test(ForecastPage::class)
        ->assertSet('horizon', 30);
});

it('keeps a horizon the rail does offer', function (): void {
    $user = fphUser('fph-listed');
    fphAccount((int) $user->id);
    test()->actingAs($user);

    expect(ForecastHorizon::days())->toContain(90);

    Livewire::withQueryParams(['horizon' => 90])
        ->test(ForecastPage::class)
        ->assertSet('horizon', 90);
});
