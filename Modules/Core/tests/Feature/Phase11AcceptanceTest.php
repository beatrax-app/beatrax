<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SystemAlertsBanner;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Tests\Helpers\RealSqliteFixture;

/*
 * Phase 11 end-to-end acceptance test.
 *
 * Walks the full vertical in a single Pest scenario, against the real
 * production command pipelines (no hand-seeded SystemAlert rows, no
 * shortcuts around `db:backup --force`):
 *
 *  1. Build a real on-disk SQLite source via RealSqliteFixture::create.
 *  2. Rebind the sqlite connection at the source so the command's
 *     VACUUM INTO targets the fixture rather than the test
 *     :memory: database.
 *  3. Override 'core.backups_directory' to a fresh sys_get_temp_dir()
 *     subtree so the test does not touch the developer's real
 *     storage/app/backups path.
 *  4. Run `db:backup --force` — assert the .sqlite + .meta.json pair
 *     are produced.
 *  5. Render the SystemAlertsBanner as an authenticated user and
 *     assert the banner is calm (no "Mark as resolved" button).
 *  6. Deliberately corrupt the source DB using the verbatim recipe
 *     from BackupCorruptionPathTest (truncate to 100 bytes — strips
 *     the sqlite_master page after the SQLite file header).
 *  7. Re-run `db:backup --force`. Either branch lands at the same
 *     user-visible failure shape: a critical
 *     system_alerts(backup_corrupt) row exists and either a .suspect
 *     file is produced under $backupsDir (post-VACUUM integrity-check
 *     trip) or the VACUUM-INTO exception bridge surfaces the alert
 *     without leaving a file. Both branches are acceptable corrupt-
 *     path outcomes (the alert is the load-bearing user-visible
 *     signal).
 *  8. Re-render the banner — assert the "failed integrity check"
 *     critical row is now visible.
 *  9. Acknowledge the alert via the Livewire `call('acknowledge', …)`
 *     handler. Next render must NOT see "failed integrity check".
 * 10. Cleanup is handled in afterEach.
 *
 * The corruption recipe is deliberately the same one
 * BackupCorruptionPathTest uses (substr of the source file's bytes,
 * dropped to 100). This keeps the cross-test reuse explicit and means
 * any future change to how the command handles corrupt sources is
 * exercised by BOTH tests on the same path.
 */

beforeEach(function (): void {
    $this->sourcePath = RealSqliteFixture::create('phase11-acceptance');

    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $this->sourcePath);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    $this->backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'diederik-phase11-acceptance-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    $this->app->instance('core.backups_directory', $this->backupsDir);

    $this->user = User::query()->create([
        'email' => 'p11-acceptance@diederik.test',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
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

    // Seed a transactions row through raw PDO so the source DB is
    // non-empty when VACUUM INTO copies it. The row is incidental to
    // the assertion — it just gives VACUUM INTO something to work on.
    $pdo = new PDO('sqlite:'.$sourcePath, options: [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("INSERT INTO transactions (id, user_id, amount_minor, currency, booked_at) VALUES (1, 1, 12345, 'EUR', '2026-05-19')");
    unset($pdo);

    // (1) Happy path — db:backup --force produces a .sqlite + sidecar.
    $this->artisan('db:backup', ['--force' => true])
        ->expectsOutputToContain('Backup written:')
        ->assertSuccessful();

    expect(is_dir($backupsDir))->toBeTrue('Backups dir must exist after the happy run.');

    $cleanSqliteFiles = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'diederik-*.sqlite');
    $cleanMetaFiles = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'diederik-*.sqlite.meta.json');
    expect($cleanSqliteFiles)->toHaveCount(1, 'Happy run must produce exactly one .sqlite backup.');
    expect($cleanMetaFiles)->toHaveCount(1, 'Happy run must produce exactly one .meta.json sidecar.');

    // (2) Banner in calm state — no critical alerts yet, no
    //     "Mark as resolved" button.
    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertDontSee('Mark as resolved')
        ->assertSeeHtml('aria-label="System alerts"');

    // (3) Corruption recipe — verbatim from BackupCorruptionPathTest.
    //     Truncate the source DB to 100 bytes. The SQLite file header
    //     is 100 bytes; truncating exactly there strips the
    //     sqlite_master page that VACUUM INTO needs to enumerate
    //     tables. The command's two-pronged handling (try/catch on
    //     VACUUM + post-VACUUM integrity_check) converges either
    //     branch onto the same `.suspect` + system_alerts outcome.
    //     Release the framework's connection handle BEFORE truncating
    //     so the file write happens against an idle file.
    $db->purge('sqlite');
    file_put_contents($sourcePath, substr((string) file_get_contents($sourcePath), 0, 100));

    // (4) Corrupt-path run — db:backup --force exits non-zero, writes
    //     a critical system_alerts(backup_corrupt) row organically
    //     through the command's recordCorruptAlert() helper. No
    //     hand-seeded SystemAlert::create call — the test exercises
    //     the production code path.
    $this->artisan('db:backup', ['--force' => true])->assertFailed();

    $corruptAlerts = SystemAlert::query()
        ->where('kind', 'backup_corrupt')
        ->where('severity', 'critical')
        ->get();
    expect($corruptAlerts)->toHaveCount(1, 'Corrupt path must record exactly one critical system_alerts row.');

    $suspectFiles = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'diederik-*.sqlite.suspect');
    if ($suspectFiles !== []) {
        // Branch A — post-VACUUM integrity_check trip: the produced
        //   VACUUM INTO output was renamed to .suspect. Verify the
        //   alert's metadata carries the suspect path.
        /** @var SystemAlert $alert */
        $alert = $corruptAlerts->first();
        /** @var array<string, mixed>|null $metadata */
        $metadata = $alert->metadata;
        expect($metadata)->toBeArray();
        expect((string) ($metadata['suspect_path'] ?? ''))->toBe((string) $suspectFiles[0]);
    } else {
        // Branch B — VACUUM-INTO exception bridge: PDOException at
        //   PRAGMA data_version or at VACUUM INTO itself. No .suspect
        //   file is produced because no output was ever written. The
        //   alert row IS still there; the user-visible failure shape
        //   converges. Accepted per BackupCorruptionPathTest's
        //   documented dual-branch behaviour — the alert is the
        //   load-bearing user-visible signal.
        /** @var SystemAlert $alert */
        $alert = $corruptAlerts->first();
        expect($alert->severity)->toBe('critical');
    }

    // (5) Banner now renders the critical row with the locked
    //     "failed integrity check" template (from system-alert-message
    //     partial backup_corrupt branch).
    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertSee('failed integrity check');

    // (6) Acknowledge via the Livewire wire:click handler. The next
    //     render must not see the row.
    /** @var SystemAlert $alert */
    $alert = $corruptAlerts->first();
    $alertId = (int) $alert->id;

    $component = Livewire::actingAs($this->user)->test(SystemAlertsBanner::class);
    $component->call('acknowledge', $alertId);
    $component->assertDontSee('failed integrity check');

    // Sanity — the row is acknowledged in the DB (audit trail
    // preserved; not deleted).
    /** @var SystemAlert $persisted */
    $persisted = SystemAlert::query()->findOrFail($alertId);
    expect($persisted->acknowledged_at)->not->toBeNull('Acknowledge action must stamp acknowledged_at.');
});
