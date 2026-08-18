<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Modules\Mobile\Commands\PackageAndroidCommand;
use Modules\Mobile\Internal\Boot\NativeBuildPatches;
use Psr\Log\NullLogger;

/*
 * This command is the only thing standing between a broken Android build and a
 * release that reports success anyway: `native:package` returns 0 on every
 * failure path it has, including with no project to build. So every refusal
 * here is load-bearing, and each one is asserted rather than assumed.
 *
 * The base path is moved to an empty directory for the duration: the command
 * must answer about a mobile root, not about this repository, and pointing it
 * at a real one would have it run the patch scripts for real. The Filesystem
 * is a double for the same reason, and `native:install` / `native:package` are
 * stubs because the packages defining them live only in mobile-app's Composer
 * root.
 */

function packageAndroidRoot(): string
{
    $root = sys_get_temp_dir().'/beatrax-package-android';

    if (! is_dir($root)) {
        mkdir($root, 0o777, true);
    }

    return $root;
}

function packageAndroid(array $files = [], array $config = [], bool $withPatchScripts = false): int
{
    $base = packageAndroidRoot();
    app()->setBasePath($base);

    if ($withPatchScripts) {
        @mkdir($base.'/scripts', 0o777, true);
        // Writes a marker so "the patches ran" is observed rather than trusted.
        file_put_contents(
            $base.'/scripts/nativephp_grant_webview_camera.php',
            '<?php file_put_contents(__DIR__."/../ran", "yes");',
        );
    }

    $defaults = [
        'isFile' => [
            $base.'/.env' => true,
            $base.'/nativephp/android/app/build.gradle.kts' => true,
            $base.'/nativephp/android/app/build/outputs/apk/release/app-release.apk' => true,
        ],
        'get' => [
            $base.'/.env' => "NATIVEPHP_APP_ID=com.beatrax.mobile\n",
            $base.'/nativephp/android/app/build.gradle.kts' => 'applicationId = "com.beatrax.mobile"'
                ."\n".'versionCode = 20000',
        ],
        'isDirectory' => true,
        'size' => 4096,
    ];

    $spec = array_replace_recursive($defaults, $files);

    $fs = Mockery::mock(Filesystem::class);
    $fs->shouldReceive('isFile')->andReturnUsing(
        static fn (string $path): bool => (bool) ($spec['isFile'][$path] ?? false),
    );
    $fs->shouldReceive('get')->andReturnUsing(
        static fn (string $path): string => (string) ($spec['get'][$path] ?? ''),
    );
    $fs->shouldReceive('isDirectory')->andReturn($spec['isDirectory']);
    $fs->shouldReceive('size')->andReturn($spec['size']);
    $fs->shouldReceive('delete')->andReturn(true);

    config(array_replace([
        'nativephp.app_id' => 'com.beatrax.mobile',
        'nativephp.version' => '2.0.0',
        'nativephp.version_code' => 20000,
    ], $config));

    app()->instance(PackageAndroidCommand::class, new PackageAndroidCommand(
        $fs,
        new NativeBuildPatches(new NullLogger),
        app('config'),
    ));

    return app(Kernel::class)->call('mobile:package-android');
}

afterEach(function (): void {
    // Recorded rather than recomputed: this suite runs from both Composer
    // roots, and path arithmetic off __DIR__ would restore the desktop root
    // even when the run started in mobile-app.
    app()->setBasePath($this->originalBasePath);

    $root = packageAndroidRoot();
    @unlink($root.'/ran');
    @unlink($root.'/scripts/nativephp_grant_webview_camera.php');
    @rmdir($root.'/scripts');
});

beforeEach(function (): void {
    $this->originalBasePath = app()->basePath();

    // Both return 0 whatever happens, which is the property the command exists
    // to compensate for — so the stubs behave the same way.
    Artisan::command('native:package {platform} {--build-type=}', fn (): int => 0);
    Artisan::command('native:install {platform} {--with-icu}', fn (): int => 0);
});

it('packages when every gate is satisfied', function (): void {
    expect(packageAndroid())->toBe(0);
});

it('refuses a build type it has no artifact path for', function (): void {
    app()->setBasePath(packageAndroidRoot());
    app()->instance(PackageAndroidCommand::class, new PackageAndroidCommand(
        Mockery::mock(Filesystem::class)->shouldIgnoreMissing(),
        new NativeBuildPatches(new NullLogger),
        app('config'),
    ));

    expect(app(Kernel::class)->call('mobile:package-android', ['--build-type' => 'debug']))->toBe(1);
});

it('refuses when no bundle id is configured', function (): void {
    expect(packageAndroid(config: ['nativephp.app_id' => '']))->toBe(1);
});

it('refuses when the .env does not pin the bundle id', function (): void {
    // A commented key reads as absent to native:install, which then invents
    // com.<user>.<random words> and ships the build under it.
    expect(packageAndroid(files: ['get' => [packageAndroidRoot().'/.env' => "# NATIVEPHP_APP_ID=\n"]]))->toBe(1);
});

it('derives the version code when only the package default is present', function (): void {
    expect(packageAndroid(config: ['nativephp.version_code' => 1]))->toBe(0)
        ->and(config('nativephp.version_code'))->toBe(20000);
});

it('refuses when no version code can be derived from the version', function (): void {
    expect(packageAndroid(config: [
        'nativephp.version_code' => 1,
        'nativephp.version' => '0.0.0-dev',
    ]))->toBe(1);
});

it('refuses when the version is not a string at all', function (): void {
    expect(packageAndroid(config: [
        'nativephp.version_code' => 1,
        'nativephp.version' => 200,
    ]))->toBe(1);
});

it('leaves an explicitly set version code alone', function (): void {
    expect(packageAndroid(
        files: ['get' => [
            packageAndroidRoot().'/nativephp/android/app/build.gradle.kts' => 'applicationId = "com.beatrax.mobile"'
                ."\n".'versionCode = 10300',
        ]],
        config: ['nativephp.version_code' => 10300],
    ))->toBe(0)->and(config('nativephp.version_code'))->toBe(10300);
});

it('recovers when native:install does create the project', function (): void {
    // The B1 path: a `composer install` checkout has no Android project,
    // because native:install runs only from post-update-cmd. The command
    // generates it and carries on rather than refusing.
    expect(packageAndroid(files: ['isDirectory' => false]))->toBe(0);
});

it('refuses when the Gradle file never appeared', function (): void {
    expect(packageAndroid(files: [
        'isDirectory' => false,
        'isFile' => [packageAndroidRoot().'/nativephp/android/app/build.gradle.kts' => false],
    ]))->toBe(1);
});

it('refuses when the identity cannot be read back at all', function (): void {
    // A half-generated project: the directory is there, so the project check
    // passes early and never looks at Gradle, but the build file itself is
    // missing — so what the APK carries cannot be established either way.
    expect(packageAndroid(files: [
        'isFile' => [packageAndroidRoot().'/nativephp/android/app/build.gradle.kts' => false],
    ]))->toBe(1);
});

it('refuses when packaging produced no artifact', function (): void {
    expect(packageAndroid(files: [
        'isFile' => [packageAndroidRoot().'/nativephp/android/app/build/outputs/apk/release/app-release.apk' => false],
    ]))->toBe(1);
});

it('goes and asks Gradle why, rather than listing what it might have been', function (): void {
    // native:package prints "Running Gradle" and then nothing on a failed
    // build, and still returns 0 — so the reason has to be fetched. With no
    // wrapper on disk it cannot be, and says so instead of staying silent.
    expect(packageAndroid(files: [
        'isFile' => [packageAndroidRoot().'/nativephp/android/app/build/outputs/apk/release/app-release.apk' => false],
    ]))->toBe(1);

    expect(Artisan::output())->toContain('Gradle cannot be asked what went wrong');
});

it('refuses an artifact of zero bytes', function (): void {
    expect(packageAndroid(files: ['size' => 0]))->toBe(1);
});

it('refuses an APK carrying a different applicationId', function (): void {
    expect(packageAndroid(files: ['get' => [
        packageAndroidRoot().'/nativephp/android/app/build.gradle.kts' => 'applicationId = "com.runner.stormlunarbold"'
            ."\n".'versionCode = 20000',
    ]]))->toBe(1);
});

it('refuses an APK carrying a different version code', function (): void {
    expect(packageAndroid(files: ['get' => [
        packageAndroidRoot().'/nativephp/android/app/build.gradle.kts' => 'applicationId = "com.beatrax.mobile"'
            ."\n".'versionCode = 1',
    ]]))->toBe(1);
});

it('applies the shell patches before packaging', function (): void {
    expect(packageAndroid(withPatchScripts: true))->toBe(0)
        ->and(packageAndroidRoot().'/ran')->toBeFile();
});

it('warns rather than fails when a tree carries no patch scripts', function (): void {
    // A materialized Bifrost tree that never had them copied in. The build is
    // worse but it is not wrong, so this is a warning and not a refusal.
    expect(packageAndroid())->toBe(0);
});
