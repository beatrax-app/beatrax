<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Anomaly\Public\Http\Livewire\AnomalySettingsSection;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

function dlfUser(string $username, string $locale): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'locale' => $locale,
    ]);
}

function dlfRule(User $user, string $detector): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $db->connection()->table('anomaly_suppression_rules')->insert([
        'user_id' => $user->id,
        'counterparty_id' => null,
        'detector' => $detector,
        'direction' => 'expense',
        'amount_band_low_minor' => -2700,
        'amount_band_high_minor' => -2000,
        'currency' => 'EUR',
        'source_anomaly_alert_id' => null,
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-20 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    app()->setLocale('en');
});

it('renders a known detector in the readers language', function (): void {
    $user = dlfUser('dlf-known', 'nl');
    dlfRule($user, 'large');
    app()->setLocale('nl');

    Livewire::actingAs($user)->test(AnomalySettingsSection::class)
        ->assertSee('Grote afschrijving');
});

it('shows a detector with no translation as its key, never as titleised English', function (): void {
    $user = dlfUser('dlf-unknown', 'nl');
    dlfRule($user, 'seasonal_spike');
    app()->setLocale('nl');

    Livewire::actingAs($user)->test(AnomalySettingsSection::class)
        ->assertSee('anomaly::settings.detectors.seasonal_spike')
        ->assertDontSee('Seasonal Spike');
});
