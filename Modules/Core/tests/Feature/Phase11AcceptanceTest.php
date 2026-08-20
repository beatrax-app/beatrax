<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SystemAlertsBanner;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Tests\Helpers\RealSqliteFixture;

beforeEach(function (): void {
    $this->sourcePath = RealSqliteFixture::create('phase11-acceptance');

    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $this->sourcePath);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    $this->backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-phase11-acceptance-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.dirname($this->backupsDir, 2));

    $this->user = User::query()->create([
        'username' => 'p11-acceptance',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
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

it('phase 11 backup banner round-trip — happy → corrupt → banner → acknowledge', function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    /** @var string $sourcePath */
    $sourcePath = $this->sourcePath;
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // Raw PDO write so the source DB is non-empty for VACUUM INTO to copy.
    $pdo = new PDO('sqlite:'.$sourcePath, options: [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("INSERT INTO transactions (id, user_id, amount_minor, currency, booked_at) VALUES (1, 1, 12345, 'EUR', '2026-05-19')");
    unset($pdo);

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

    // The SQLite file header is exactly 100 bytes, so truncating there strips
    // the sqlite_master page VACUUM INTO needs to enumerate tables. Purge the
    // framework's connection handle first so the write lands on an idle file.
    $db->purge('sqlite');
    file_put_contents($sourcePath, substr((string) file_get_contents($sourcePath), 0, 100));

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
    if ($suspectFiles !== []) {
        // Post-VACUUM integrity_check tripped: the output was renamed .suspect.
        /** @var SystemAlert $alert */
        $alert = $corruptAlerts->first();
        /** @var array<string, mixed>|null $metadata */
        $metadata = $alert->metadata;
        expect($metadata)->toBeArray();
        expect((string) ($metadata['suspect_path'] ?? ''))
            ->toContain('.suspect', 'Alert metadata must reference the suspect file.');
    } else {
        // The exception bridge fired before any output existed (PDOException at
        // PRAGMA data_version), so there is no .suspect file — only the alert.
        /** @var SystemAlert $alert */
        $alert = $corruptAlerts->first();
        expect($alert->severity)->toBe('critical');
    }

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertSee('failed integrity check');

    /** @var SystemAlert $alert */
    $alert = $corruptAlerts->first();
    $alertId = (int) $alert->id;

    $component = Livewire::actingAs($this->user)->test(SystemAlertsBanner::class);
    $component->call('acknowledge', $alertId);
    $component->assertDontSee('failed integrity check');

    // Acknowledged, not deleted — the audit trail survives.
    /** @var SystemAlert $persisted */
    $persisted = SystemAlert::withoutGlobalScopes()->findOrFail($alertId);
    expect($persisted->acknowledged_at)->not->toBeNull('Acknowledge action must stamp acknowledged_at.');
});
