<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Core\Internal\Enums\BackupAlertKind;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Http\Livewire\SystemAlertsBanner;

// The banner told the reader to run `php artisan db:backup`. Neither shipped
// bundle carries a terminal: the desktop is an Electron window and the phone is
// a WebView, and BackupFreshnessProbe clears this row only when db.backup-daily
// writes a verified sidecar. The daily run is real; the instruction never was.

/** @return list<string> the locales whose overdue copy still orders a command line */
function localesOrderingACommand(): array
{
    $ordering = [];

    foreach ((array) glob(base_path('Modules/Core/Resources/lang/*/alerts.php')) as $file) {
        /** @var array{messages: array<string, string>} $strings */
        $strings = require (string) $file;
        $copy = $strings['messages']['backup_overdue'];

        foreach (['artisan', '<code'] as $terminal) {
            if (str_contains($copy, $terminal)) {
                $ordering[] = basename(dirname((string) $file));
                break;
            }
        }
    }

    return $ordering;
}

function overdueBannerFor(User $user, ?int $hoursOld): Testable
{
    app(DatabaseManager::class)->connection()->table('system_alerts')->insert([
        'user_id' => $user->id,
        'kind' => BackupAlertKind::Overdue->value,
        'severity' => SystemAlertSeverity::Warning->value,
        'message' => 'raw-fallthrough-marker',
        'metadata' => json_encode(['hours_old' => $hoursOld]),
        'created_at' => '2026-05-20 01:00:00',
        'acknowledged_at' => null,
    ]);

    return Livewire::actingAs($user)->test(SystemAlertsBanner::class);
}

it('orders no command line in any language', function (): void {
    $ordering = localesOrderingACommand();

    expect($ordering)->toBe([], 'These locales still tell the reader to run a command no shipped build offers: '.implode(', ', $ordering).'.');
});

it('keeps the age and the daily run, which are the accurate half', function (): void {
    $reader = User::query()->create([
        'username' => 'overdue-banner',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    overdueBannerFor($reader, 73)
        ->assertSee('73h old')
        ->assertSee('once a day')
        ->assertDontSee('php artisan db:backup');
});

// The probe records this row with a null age when it finds no backup at all,
// and the age sentence turned that into a backup somebody had just made.
it('says no backup was found rather than reporting one made zero hours ago', function (): void {
    $reader = User::query()->create([
        'username' => 'overdue-banner-none',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    overdueBannerFor($reader, null)
        ->assertSee('No verified backup was found')
        ->assertDontSee('0h old');
});
