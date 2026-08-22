<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Internal\Enums\BackupAlertKind;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Http\Livewire\SystemAlertsBanner;

// system_alerts.kind carries no CHECK trigger and every module mints its own
// values, so a kind is a private contract between one raiser and the surfaces
// that read the row back.

// The lang keys and the rows in databases already on disk both spell these,
// so the enum is pinned to the literals rather than only to itself.
it('spells the two backup kinds the way the rows on disk and the copy keys do', function (): void {
    expect(BackupAlertKind::Corrupt->value)->toBe('backup_corrupt')
        ->and(BackupAlertKind::Overdue->value)->toBe('backup_overdue')
        ->and(trans('core::alerts.messages.backup_overdue'))->not->toBe('core::alerts.messages.backup_overdue')
        ->and(trans('core::alerts.messages.backup_corrupt_no_path'))->not->toBe('core::alerts.messages.backup_corrupt_no_path');
});

// An unknown kind falls through to the row's own `message` column, so a
// drifted spelling shows the operator's raw text instead of the template and
// nothing errors.
it('renders the overdue template rather than falling through to the raw message', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $user = User::query()->create([
        'username' => 'backup-kind-vocabulary',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $db->connection()->table('system_alerts')->insert([
        'user_id' => $user->id,
        'kind' => BackupAlertKind::Overdue->value,
        'severity' => SystemAlertSeverity::Warning->value,
        'message' => 'raw-fallthrough-marker',
        'metadata' => json_encode(['hours_old' => 61]),
        'created_at' => '2026-05-20 01:00:00',
        'acknowledged_at' => null,
    ]);

    Livewire::actingAs($user)->test(SystemAlertsBanner::class)
        ->assertSee('61h')
        ->assertDontSee('raw-fallthrough-marker');
});
