<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\DriftAlerts\Tests\Support\DriftAlertFixture;
use Tests\Helpers\LivewireRoundTrip;

uses(RefreshDatabase::class);

// recurringSeriesId names the row save() writes drift_threshold_percent to, and
// mount() proves nothing about it: on /drift the parent passes
// currentValueLoaded, so the ownership-bearing read never runs. Unlocked, a
// replayed snapshot naming a second series wrote that series' threshold and
// replicated it to every paired device, while the editor still read as the
// alert the reader had open.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
    $this->user = DriftAlertFixture::user('drift-threshold-lock');
    $this->actingAs($this->user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->onScreen = DriftAlertFixture::alert($this->user)->recurring_series_id;
    $this->neighbour = DriftAlertFixture::alert($this->user)->recurring_series_id;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function driftThresholdOf(int $seriesId): ?int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $stored = $db->connection()->table('recurring_series')->where('id', $seriesId)->value('drift_threshold_percent');

    return $stored === null ? null : (int) $stored;
}

function driftThresholdEditorSnapshot(int $seriesId): string
{
    return LivewireRoundTrip::snapshotFor(
        (string) Livewire::mount('drift-alerts.drift-threshold-editor', [
            'recurringSeriesId' => $seriesId,
            'currentValue' => null,
            'currentValueLoaded' => true,
        ]),
        'drift-alerts.drift-threshold-editor',
    );
}

it('refuses a payload that moves the save onto a second series', function (): void {
    $response = LivewireRoundTrip::tamper(
        $this,
        driftThresholdEditorSnapshot($this->onScreen),
        ['recurringSeriesId' => $this->neighbour],
        [['path' => '', 'method' => 'save', 'params' => [50]]],
    );

    $response->assertForbidden();

    expect(driftThresholdOf($this->neighbour))->toBeNull()
        ->and(driftThresholdOf($this->onScreen))->toBeNull();
});

it('still writes the threshold of the series the editor was mounted for', function (): void {
    LivewireRoundTrip::tamper(
        $this,
        driftThresholdEditorSnapshot($this->onScreen),
        [],
        [['path' => '', 'method' => 'save', 'params' => [50]]],
    )->assertOk();

    expect(driftThresholdOf($this->onScreen))->toBe(50)
        ->and(driftThresholdOf($this->neighbour))->toBeNull();
});
