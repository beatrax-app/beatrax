<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Internal\Console\InstallCommand;

// Three overrides keep the developer's real machine out of the test: plists go
// to a sandbox directory instead of ~/Library/LaunchAgents, launchctl is
// recorded rather than run, and the host's OS is answered by the test rather
// than by the runner it happens to be on.
//
// The last of those is why this file exists at all in a report. Every job in
// the pipeline runs on ubuntu, so a `PHP_OS_FAMILY !== 'Darwin'` skip in
// beforeEach retired the whole file: four tests that were counted in every run
// and executed in none of them, leaving the plist rendering they cover — the
// substitutions, the --without-redis branch, the launchctl bootstrap call —
// asserted nowhere.
final class CaptureBootstrapInstallCommand extends InstallCommand
{
    /** @var list<array{uid: int, plistPath: string}> */
    public static array $capturedBootstraps = [];

    public static ?string $sandboxDir = null;

    public static bool $hostIsMacOs = true;

    protected function hostIsMacOs(): bool
    {
        return self::$hostIsMacOs;
    }

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
    CaptureBootstrapInstallCommand::$hostIsMacOs = true;

    // A per-test sandbox so parallel runs do not contend on one path.
    $sandbox = sys_get_temp_dir().'/beatrax-launchd-test-'.bin2hex(random_bytes(6));
    mkdir($sandbox, 0700, recursive: true);
    CaptureBootstrapInstallCommand::$sandboxDir = $sandbox;
    CaptureBootstrapInstallCommand::$capturedBootstraps = [];
    $this->sandbox = $sandbox;

    // Symfony console resolves commands by class, so rebinding InstallCommand is
    // enough for $this->artisan('beatrax:install') to pick up the subclass.
    $this->app->bind(
        InstallCommand::class,
        static fn ($app) => new CaptureBootstrapInstallCommand(
            $app->make(Repository::class),
            $app->make(Dispatcher::class),
            $app->make(DatabaseManager::class),
            $app->make(Filesystem::class),
            $app->make(Application::class),
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
    CaptureBootstrapInstallCommand::$hostIsMacOs = true;
});

it('renders horizon + scheduler plists with placeholders substituted, skips redis when --without-redis is passed', function (): void {
    $this->artisan('beatrax:install', ['--launchd' => true, '--without-redis' => true])
        ->assertExitCode(0);

    $horizonPath = $this->sandbox.'/com.beatrax.horizon.plist';
    $schedulerPath = $this->sandbox.'/com.beatrax.scheduler.plist';
    $redisPath = $this->sandbox.'/com.beatrax.redis.plist';

    expect(file_exists($horizonPath))->toBeTrue();
    expect(file_exists($schedulerPath))->toBeTrue();
    expect(file_exists($redisPath))->toBeFalse();

    $horizonContents = (string) file_get_contents($horizonPath);
    expect($horizonContents)->not->toContain('{{ABS_PHP_BINARY}}');
    expect($horizonContents)->not->toContain('{{ABS_PROJECT_ROOT}}');
    expect($horizonContents)->toContain(PHP_BINARY);
    expect($horizonContents)->toContain(base_path());

    $schedulerContents = (string) file_get_contents($schedulerPath);
    expect($schedulerContents)->not->toContain('{{ABS_PHP_BINARY}}');
    expect($schedulerContents)->toContain('schedule:work');

    expect(CaptureBootstrapInstallCommand::$capturedBootstraps)->toHaveCount(2);
    $captured = array_map(
        static fn (array $c): string => $c['plistPath'],
        CaptureBootstrapInstallCommand::$capturedBootstraps,
    );
    expect($captured)->toContain($horizonPath);
    expect($captured)->toContain($schedulerPath);
});

it('renders all three plists (horizon + scheduler + redis) when --without-redis is omitted', function (): void {
    $this->artisan('beatrax:install', ['--launchd' => true])
        ->assertExitCode(0);

    $horizonPath = $this->sandbox.'/com.beatrax.horizon.plist';
    $schedulerPath = $this->sandbox.'/com.beatrax.scheduler.plist';
    $redisPath = $this->sandbox.'/com.beatrax.redis.plist';

    expect(file_exists($horizonPath))->toBeTrue();
    expect(file_exists($schedulerPath))->toBeTrue();
    expect(file_exists($redisPath))->toBeTrue();

    $redisContents = (string) file_get_contents($redisPath);
    expect($redisContents)->toContain('redis:7-alpine');
    expect($redisContents)->toContain('127.0.0.1:6379:6379');
    // Redis has no PHP dependency, so only the project-root token is substituted.
    expect($redisContents)->not->toContain('{{ABS_PROJECT_ROOT}}');
    expect($redisContents)->toContain(base_path());

    expect(CaptureBootstrapInstallCommand::$capturedBootstraps)->toHaveCount(3);
});

it('renders plists that contain the substituted PHP_BINARY path used by the artisan ProgramArguments', function (): void {
    $this->artisan('beatrax:install', ['--launchd' => true, '--without-redis' => true])
        ->assertExitCode(0);

    $horizonContents = (string) file_get_contents($this->sandbox.'/com.beatrax.horizon.plist');
    // The launchd-managed process must run against this checkout's artisan.
    expect($horizonContents)->toContain(base_path().'/artisan');
    expect($horizonContents)->toContain('<string>horizon</string>');

    $schedulerContents = (string) file_get_contents($this->sandbox.'/com.beatrax.scheduler.plist');
    expect($schedulerContents)->toContain(base_path().'/artisan');
    expect($schedulerContents)->toContain('<string>schedule:work</string>');
});

it('outputs a Wrote line for each installed plist', function (): void {
    $this->artisan('beatrax:install', ['--launchd' => true, '--without-redis' => true])
        ->expectsOutputToContain('Wrote '.$this->sandbox.'/com.beatrax.horizon.plist')
        ->expectsOutputToContain('Wrote '.$this->sandbox.'/com.beatrax.scheduler.plist')
        ->assertExitCode(0);
});

// The refusal is the branch every non-macOS host actually takes, and it was the
// one the skip guaranteed nobody would ever see run: the command has to say so
// and write nothing, not write plists a launchd that is not there would never
// read.
it('refuses to install launchd plists on a host that is not macOS', function (): void {
    CaptureBootstrapInstallCommand::$hostIsMacOs = false;

    $this->artisan('beatrax:install', ['--launchd' => true, '--without-redis' => true])
        ->expectsOutputToContain('launchd plists are macOS-only; aborting.')
        ->assertExitCode(1);

    expect(glob($this->sandbox.'/*.plist'))->toBe([]);
    expect(CaptureBootstrapInstallCommand::$capturedBootstraps)->toBe([]);
});

// The override above is what keeps the suite off the runner's OS, and an
// override is also how a seam stops being read at all: every case here answers
// hostIsMacOs() itself, so the real one runs nowhere. This asks the shipped one
// what it thinks, which is the reading a released binary actually takes.
it('answers the host OS from the runtime the process is on', function (): void {
    // The double is set to the opposite of the truth first, so resolving it by
    // mistake cannot pass: app(InstallCommand::class) returns the subclass
    // beforeEach binds, which is the override this case exists to see past.
    CaptureBootstrapInstallCommand::$hostIsMacOs = PHP_OS_FAMILY !== 'Darwin';

    $command = new InstallCommand(
        app(Repository::class),
        app(Dispatcher::class),
        app(DatabaseManager::class),
        app(Filesystem::class),
        app(Application::class),
    );

    expect((new ReflectionMethod($command, 'hostIsMacOs'))->invoke($command))
        ->toBe(PHP_OS_FAMILY === 'Darwin');
});
