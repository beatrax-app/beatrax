<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Http\Livewire\SystemAlertsBanner;
use Tests\Helpers\LiveSqliteConnection;
use Tests\Helpers\RealSqliteFixture;

beforeEach(function (): void {
    // The command derives its destination from the clock at seconds
    // resolution, and the second run below has to land on the path the first
    // one already wrote. Unfrozen, the two drift into different seconds.
    CarbonImmutable::setTestNow('2026-07-29 12:00:00');

    // No seeded schema: this file is the live database for the whole test, so
    // the banner, the acting user and the alert row all have to fit in it.
    $this->sourcePath = RealSqliteFixture::create('phase11-acceptance', []);

    $this->backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-phase11-acceptance-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.dirname($this->backupsDir, 2));

    LiveSqliteConnection::pointAt($this->app, $this->sourcePath);
    $this->artisan('migrate', ['--database' => LiveSqliteConnection::NAME, '--force' => true])->assertSuccessful();

    $this->user = User::query()->create([
        'username' => 'p11-acceptance',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    LiveSqliteConnection::restore($this->app);
    CarbonImmutable::setTestNow(null);
    putenv('NATIVEPHP_STORAGE_PATH');

    /** @var string $sourcePath */
    $sourcePath = $this->sourcePath;
    RealSqliteFixture::cleanup($sourcePath);

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    if (is_dir($backupsDir)) {
        foreach ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'*') as $file) {
            if (is_file((string) $file)) {
                @unlink((string) $file);
            }
        }
        @rmdir($backupsDir);
        @rmdir(dirname($backupsDir));
        @rmdir(dirname($backupsDir, 2));
    }
});

it('backup banner round-trip — happy → corrupt → banner → acknowledge', function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;

    $this->artisan('db:backup', ['--force' => true])
        ->expectsOutputToContain('Backup written:')
        ->assertSuccessful();

    expect(is_dir($backupsDir))->toBeTrue('Backups dir must exist after the happy run.');
    $beatraxEntries = array_values(array_filter(
        scandir($backupsDir),
        static fn (string $name): bool => str_starts_with($name, 'beatrax-'),
    ));
    expect($beatraxEntries)->not->toBe([], 'Happy run must produce beatrax-* artifacts.');

    $cleanSqliteFiles = array_values(array_filter(
        scandir($backupsDir),
        static fn (string $name): bool => str_ends_with($name, '.sqlite'),
    ));
    $cleanMetaFiles = array_values(array_filter(
        scandir($backupsDir),
        static fn (string $name): bool => str_ends_with($name, '.sqlite.meta.json'),
    ));
    expect($cleanSqliteFiles)->toHaveCount(1, 'Happy run must produce exactly one .sqlite backup.');
    expect($cleanMetaFiles)->toHaveCount(1, 'Happy run must produce exactly one .meta.json sidecar.');

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertDontSee('Mark as resolved')
        ->assertSeeHtml('aria-label="System alerts"');

    // Corrupting the live file instead would take the alert row down with it:
    // the row is written into the database the command just found unreadable.
    // VACUUM INTO refusing an occupied destination fails the same phase and
    // leaves the ledger readable, which is what the banner half needs.
    $this->artisan('db:backup', ['--force' => true])->assertFailed();

    // Corrupt-path alerts are system-wide (`user_id IS NULL`), which the
    // BelongsToUser global scope would hide from an acting-as user.
    $corruptAlerts = SystemAlert::withoutGlobalScopes()
        ->where('kind', 'backup_corrupt')
        ->where('severity', 'critical')
        ->get();
    expect($corruptAlerts)->toHaveCount(1, 'Corrupt path must record exactly one critical system_alerts row.');

    $suspectFiles = array_values(array_filter(
        scandir($backupsDir),
        static fn (string $name): bool => str_ends_with($name, '.sqlite.suspect'),
    ));
    expect($suspectFiles)->toHaveCount(1, 'The file in the way must be preserved as .suspect.');

    /** @var SystemAlert $alert */
    $alert = $corruptAlerts->first();
    /** @var array<string, mixed>|null $metadata */
    $metadata = $alert->metadata;
    expect($metadata)->toBeArray();
    expect((string) ($metadata['suspect_path'] ?? ''))->toBe(
        $backupsDir.DIRECTORY_SEPARATOR.$suspectFiles[0],
        'Alert metadata must reference the suspect file.',
    );

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertSee('failed integrity check');

    $alertId = (int) $alert->id;

    $component = Livewire::actingAs($this->user)->test(SystemAlertsBanner::class);
    $component->call('acknowledge', $alertId);
    $component->assertDontSee('failed integrity check');

    // Acknowledged, not deleted — the audit trail survives.
    /** @var SystemAlert $persisted */
    $persisted = SystemAlert::withoutGlobalScopes()->findOrFail($alertId);
    expect($persisted->acknowledged_at)->not->toBeNull('Acknowledge action must stamp acknowledged_at.');
});
