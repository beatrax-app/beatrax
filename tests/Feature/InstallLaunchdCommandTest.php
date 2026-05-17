<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Internal\Console\InstallCommand;

/*
 * `diederik:install --launchd` plist install path.
 *
 * Plan 09: the artisan command must render the three deploy/launchd
 * plist templates with PHP_BINARY + base_path() substituted, write
 * them into ~/Library/LaunchAgents/, and bootstrap each via launchctl.
 * The Redis plist is OPTIONAL (skipped via `--without-redis`).
 *
 * Test invariants:
 *  - Non-Darwin hosts: skip (the production code refuses to run on
 *    non-Darwin; the test mirrors that).
 *  - LaunchAgents writes land in a sandbox dir (NOT the developer's
 *    real ~/Library/LaunchAgents) — achieved via a subclass that
 *    overrides resolveLaunchAgentsDir.
 *  - launchctl bootstrap is NOT actually called (the developer's real
 *    launchd must stay untouched) — the subclass overrides
 *    bootstrapPlist to capture the call without invoking the binary.
 *  - The rendered plists contain PHP_BINARY's value (NOT the literal
 *    `{{ABS_PHP_BINARY}}` placeholder).
 *  - With `--without-redis`: only horizon + scheduler land.
 *  - Without `--without-redis`: all three land.
 *  - The command exits 0 (SUCCESS).
 */

/**
 * Subclass of InstallCommand that:
 *  - Redirects ~/Library/LaunchAgents to a per-test sandbox path.
 *  - Captures the bootstrap calls instead of invoking launchctl.
 */
final class CaptureBootstrapInstallCommand extends InstallCommand
{
    /** @var list<array{uid: int, plistPath: string}> */
    public static array $capturedBootstraps = [];

    public static ?string $sandboxDir = null;

    protected function resolveLaunchAgentsDir(string $home): string
    {
        if (self::$sandboxDir === null) {
            return parent::resolveLaunchAgentsDir($home);
        }

        return self::$sandboxDir;
    }

    protected function bootstrapPlist(int $uid, string $plistPath): int
    {
        self::$capturedBootstraps[] = ['uid' => $uid, 'plistPath' => $plistPath];

        return 0;
    }
}

beforeEach(function (): void {
    if (PHP_OS_FAMILY !== 'Darwin') {
        $this->markTestSkipped('launchd plist install is macOS-only; skipping on '.PHP_OS_FAMILY);
    }

    // Fresh per-test sandbox under sys_get_temp_dir() so a parallel
    // test run does not contend on the same path.
    $sandbox = sys_get_temp_dir().'/diederik-launchd-test-'.bin2hex(random_bytes(6));
    mkdir($sandbox, 0700, recursive: true);
    CaptureBootstrapInstallCommand::$sandboxDir = $sandbox;
    CaptureBootstrapInstallCommand::$capturedBootstraps = [];
    $this->sandbox = $sandbox;

    // Rebind the InstallCommand the artisan kernel resolves so
    // `$this->artisan('diederik:install', ...)` picks up the test
    // subclass. The Symfony console binds commands by their resolved
    // class; rebinding the abstract InstallCommand here is sufficient.
    $this->app->bind(
        InstallCommand::class,
        static fn ($app) => new CaptureBootstrapInstallCommand(
            $app->make(Repository::class),
            $app->make(Dispatcher::class),
            $app->make(DatabaseManager::class),
            $app->make(Filesystem::class),
        ),
    );
});

afterEach(function (): void {
    if (isset($this->sandbox) && is_string($this->sandbox) && is_dir($this->sandbox)) {
        $files = glob($this->sandbox.'/*');
        if (is_array($files)) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        @rmdir($this->sandbox);
    }
    CaptureBootstrapInstallCommand::$sandboxDir = null;
    CaptureBootstrapInstallCommand::$capturedBootstraps = [];
});

it('renders horizon + scheduler plists with placeholders substituted, skips redis when --without-redis is passed', function (): void {
    $this->artisan('diederik:install', ['--launchd' => true, '--without-redis' => true])
        ->assertExitCode(0);

    $horizonPath = $this->sandbox.'/com.diederik.horizon.plist';
    $schedulerPath = $this->sandbox.'/com.diederik.scheduler.plist';
    $redisPath = $this->sandbox.'/com.diederik.redis.plist';

    expect(file_exists($horizonPath))->toBeTrue();
    expect(file_exists($schedulerPath))->toBeTrue();
    expect(file_exists($redisPath))->toBeFalse();

    $horizonContents = (string) file_get_contents($horizonPath);
    // Placeholders MUST be substituted — neither token can remain.
    expect($horizonContents)->not->toContain('{{ABS_PHP_BINARY}}');
    expect($horizonContents)->not->toContain('{{ABS_PROJECT_ROOT}}');
    // The substituted values MUST appear.
    expect($horizonContents)->toContain(PHP_BINARY);
    expect($horizonContents)->toContain(base_path());

    $schedulerContents = (string) file_get_contents($schedulerPath);
    expect($schedulerContents)->not->toContain('{{ABS_PHP_BINARY}}');
    expect($schedulerContents)->toContain('schedule:work');

    // launchctl bootstrap was captured (NOT actually invoked) for the
    // two installed plists.
    expect(CaptureBootstrapInstallCommand::$capturedBootstraps)->toHaveCount(2);
    $captured = array_map(
        static fn (array $c): string => $c['plistPath'],
        CaptureBootstrapInstallCommand::$capturedBootstraps,
    );
    expect($captured)->toContain($horizonPath);
    expect($captured)->toContain($schedulerPath);
});

it('renders all three plists (horizon + scheduler + redis) when --without-redis is omitted', function (): void {
    $this->artisan('diederik:install', ['--launchd' => true])
        ->assertExitCode(0);

    $horizonPath = $this->sandbox.'/com.diederik.horizon.plist';
    $schedulerPath = $this->sandbox.'/com.diederik.scheduler.plist';
    $redisPath = $this->sandbox.'/com.diederik.redis.plist';

    expect(file_exists($horizonPath))->toBeTrue();
    expect(file_exists($schedulerPath))->toBeTrue();
    expect(file_exists($redisPath))->toBeTrue();

    $redisContents = (string) file_get_contents($redisPath);
    expect($redisContents)->toContain('redis:7-alpine');
    expect($redisContents)->toContain('127.0.0.1:6379:6379');
    // The redis plist references the project root for log paths but
    // not PHP_BINARY (Redis has no PHP dependency).
    expect($redisContents)->not->toContain('{{ABS_PROJECT_ROOT}}');
    expect($redisContents)->toContain(base_path());

    expect(CaptureBootstrapInstallCommand::$capturedBootstraps)->toHaveCount(3);
});

it('renders plists that contain the substituted PHP_BINARY path used by the artisan ProgramArguments', function (): void {
    $this->artisan('diederik:install', ['--launchd' => true, '--without-redis' => true])
        ->assertExitCode(0);

    $horizonContents = (string) file_get_contents($this->sandbox.'/com.diederik.horizon.plist');
    // The plist's ProgramArguments must reference the absolute path
    // to the artisan script in this project (so the launchd-managed
    // process runs against the correct codebase).
    expect($horizonContents)->toContain(base_path().'/artisan');
    expect($horizonContents)->toContain('<string>horizon</string>');

    $schedulerContents = (string) file_get_contents($this->sandbox.'/com.diederik.scheduler.plist');
    expect($schedulerContents)->toContain(base_path().'/artisan');
    expect($schedulerContents)->toContain('<string>schedule:work</string>');
});

it('outputs a Wrote line for each installed plist', function (): void {
    $this->artisan('diederik:install', ['--launchd' => true, '--without-redis' => true])
        ->expectsOutputToContain('Wrote '.$this->sandbox.'/com.diederik.horizon.plist')
        ->expectsOutputToContain('Wrote '.$this->sandbox.'/com.diederik.scheduler.plist')
        ->assertExitCode(0);
});
