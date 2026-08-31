<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\DriftAlerts\Public\Http\Livewire\DriftThresholdEditor;
use Modules\DriftAlerts\Tests\Support\DriftAlertFixture;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = DriftAlertFixture::user('tter');
    $this->actingAs($this->user);
});

// architecture.md states DriftAlerts never issues a raw SELECT against another
// module's table, and RecurringSeriesQuery::driftThresholdForSeries already
// answered exactly this question.
it('reads the per-series override through the Recurring public surface', function (): void {
    $alert = DriftAlertFixture::alert($this->user);
    $this->db->connection()->table('recurring_series')
        ->where('id', $alert->recurring_series_id)
        ->update(['drift_threshold_percent' => 25]);

    Livewire::test(DriftThresholdEditor::class, ['recurringSeriesId' => $alert->recurring_series_id])
        ->assertSet('currentValue', 25);
});

it('reads null for a series that follows the global default', function (): void {
    $alert = DriftAlertFixture::alert($this->user);

    Livewire::test(DriftThresholdEditor::class, ['recurringSeriesId' => $alert->recurring_series_id])
        ->assertSet('currentValue', null);
});

// The Public surface scopes by user, so a cross-user id answers null rather
// than leaking another household member's setting.
it('reads null for a series belonging to somebody else', function (): void {
    $other = DriftAlertFixture::user('tter-other');
    $alert = DriftAlertFixture::alert($other);
    $this->db->connection()->table('recurring_series')
        ->where('id', $alert->recurring_series_id)
        ->update(['drift_threshold_percent' => 50]);

    Livewire::test(DriftThresholdEditor::class, ['recurringSeriesId' => $alert->recurring_series_id])
        ->assertSet('currentValue', null);
});
