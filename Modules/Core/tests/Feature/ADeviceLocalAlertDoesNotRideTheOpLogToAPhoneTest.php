<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\SystemAlert;

uses(RefreshDatabase::class);

// `system_alerts` is a covered table, and the backfill scopes what it carries
// by user_id. So a system_alerts row's OWNER decides whether it crosses to a
// paired device, and every device-local diagnostic must be written system-wide.
//
// The demo seeder gave all of its rows a user_id. A freshly paired iPhone then
// showed the desktop's WAL-mode warning, which is a fact about another
// machine's database. The sentence itself has since been rewritten — it used to
// end by naming a terminal command, which no reader of a shipped bundle can
// run — but the ownership defect this file holds is the separate one.

// Each kind against the production writer whose ownership the demo must copy.
const ALERT_OWNERSHIP = [
    'backup_corrupt' => ['owned' => false, 'writer' => 'RestoreDatabaseCommand'],
    'wal_mode_missing' => ['owned' => false, 'writer' => 'HealthCheckListener'],
    'update.available' => ['owned' => false, 'writer' => 'RecordUpdateAvailableAlert'],
    'auth.recovery_code_failed' => ['owned' => true, 'writer' => 'RecoveryCodeAuthenticator'],
];

it('seeds every alert under the owner its production writer would give it', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $rows = SystemAlert::withoutGlobalScopes()->get(['kind', 'user_id']);

    // A seeder that wrote nothing would pass every assertion below it.
    expect($rows)->not->toBeEmpty('demo:seed wrote no system alerts at all');

    $offenders = [];

    foreach ($rows as $row) {
        $expected = ALERT_OWNERSHIP[$row->kind] ?? null;

        if ($expected === null) {
            $offenders[] = $row->kind.' is seeded but named nowhere above — add it with the owner its writer gives it';

            continue;
        }

        $isOwned = $row->user_id !== null;

        if ($isOwned !== $expected['owned']) {
            $offenders[] = $expected['owned']
                ? $row->kind.' is seeded system-wide, but '.$expected['writer'].' scopes it to a user'
                : $row->kind.' carries a user_id, so it rides the op log to every paired device — '
                    .$expected['writer'].' writes it system-wide';
        }
    }

    expect($offenders)->toBe([], implode("\n  ", ['Demo ownership disagrees with production:', ...$offenders]));
});

it('leaves the machine-local kinds where a paired device can never receive them', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $travelling = SystemAlert::withoutGlobalScopes()
        ->whereNotNull('user_id')
        ->pluck('kind')
        ->unique()
        ->values()
        ->all();

    // Only the recovery failure is about the account rather than the machine,
    // so it is the only kind a second device has any business receiving.
    expect($travelling)->toBe(['auth.recovery_code_failed']);
});
