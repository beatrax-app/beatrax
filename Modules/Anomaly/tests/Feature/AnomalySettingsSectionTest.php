<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\Anomaly\Internal\Http\Livewire\AnomalySettingsSection;
use Modules\Anomaly\Internal\Jobs\BackfillAnomaliesJob;
use Modules\Anomaly\Models\AnomalySuppressionRule;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

function assUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function assRule(User $user, ?int $sourceAlertId = null): AnomalySuppressionRule
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $id = $db->connection()->table('anomaly_suppression_rules')->insertGetId([
        'user_id' => $user->id,
        'counterparty_id' => null,
        'detector' => 'large',
        'direction' => 'expense',
        'amount_band_low_minor' => -2700,
        'amount_band_high_minor' => -2000,
        'currency' => 'EUR',
        'source_anomaly_alert_id' => $sourceAlertId,
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    return AnomalySuppressionRule::query()->findOrFail($id);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-20 09:00:00');
    $this->user = assUser('ass-a');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('persists sensitivity + floor to the users columns', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    Livewire::actingAs($this->user)
        ->test(AnomalySettingsSection::class)
        ->set('anomalySensitivityPercent', 75)
        ->set('anomalyMinAmountMinor', 2500)
        ->call('save')
        ->assertSet('saved', true);

    $row = $db->connection()->table('users')->where('id', $this->user->id)
        ->first(['anomaly_sensitivity_percent', 'anomaly_min_amount_minor']);

    expect((int) $row->anomaly_sensitivity_percent)->toBe(75)
        ->and((int) $row->anomaly_min_amount_minor)->toBe(2500);
});

it('rejects an out-of-range sensitivity server-side and does not persist', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    Livewire::actingAs($this->user)
        ->test(AnomalySettingsSection::class)
        ->set('anomalySensitivityPercent', 150)
        ->call('save')
        ->assertSet('saved', false)
        ->assertSet('saveError', 'Sensitivity must be between 1 and 100.');

    $sensitivity = (int) $db->connection()->table('users')->where('id', $this->user->id)
        ->value('anomaly_sensitivity_percent');

    // 50 is the schema default, i.e. untouched.
    expect($sensitivity)->toBe(50);
});

it('rejects a negative floor server-side', function (): void {
    Livewire::actingAs($this->user)
        ->test(AnomalySettingsSection::class)
        ->set('anomalyMinAmountMinor', -1)
        ->call('save')
        ->assertSet('saved', false)
        ->assertSet('saveError', 'Minimum charge amount cannot be negative.');
});

it('dispatches BackfillAnomaliesJob exactly once on first save and never again', function (): void {
    Bus::fake([BackfillAnomaliesJob::class]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    Livewire::actingAs($this->user)
        ->test(AnomalySettingsSection::class)
        ->set('anomalySensitivityPercent', 60)
        ->call('save')
        ->assertSet('saved', true);

    Bus::assertDispatchedTimes(BackfillAnomaliesJob::class, 1);

    // Simulate the backfill completing (it stamps the guard column).
    $db->connection()->table('users')->where('id', $this->user->id)
        ->update(['anomaly_backfilled_at' => '2026-06-20 09:05:00']);

    Livewire::actingAs($this->user)
        ->test(AnomalySettingsSection::class)
        ->set('anomalySensitivityPercent', 65)
        ->call('save')
        ->assertSet('saved', true);

    Bus::assertDispatchedTimes(BackfillAnomaliesJob::class, 1);
});

it('lists the user suppression rules and removes one', function (): void {
    $rule = assRule($this->user);

    $component = Livewire::actingAs($this->user)
        ->test(AnomalySettingsSection::class)
        ->assertSee('Suppression rules')
        ->assertSee('Large charge');

    $component->call('removeSuppressionRule', $rule->id);

    expect(AnomalySuppressionRule::query()->where('id', $rule->id)->count())->toBe(0);
});

it('does not remove another user rule (cross-user no-op)', function (): void {
    $other = assUser('ass-b');
    $otherRule = assRule($other);

    Livewire::actingAs($this->user)
        ->test(AnomalySettingsSection::class)
        ->call('removeSuppressionRule', $otherRule->id);

    // Raw DB read: the BelongsToUser global scope would hide the other
    // user's row and so mask a true deletion.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $survives = $db->connection()->table('anomaly_suppression_rules')
        ->where('id', $otherRule->id)
        ->count();
    expect($survives)->toBe(1);
});

it('shows the empty state when there are no suppression rules', function (): void {
    Livewire::actingAs($this->user)
        ->test(AnomalySettingsSection::class)
        ->assertSee('No suppression rules yet.');
});
