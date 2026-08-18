<?php

declare(strict_types=1);

namespace Modules\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Modules\Mobile\Internal\Boot\AndroidVersionCode;
use Modules\Mobile\Internal\Boot\NativeBuildPatches;
use Modules\Mobile\Internal\Boot\PinnedAppId;
use Symfony\Component\Process\Process;

/**
 * @link ../../../.docs/features/mobile/architecture.md
 */
final class PackageAndroidCommand extends Command
{
    /** @var string */
    protected $signature = 'mobile:package-android {--build-type=release : release for a signed APK, bundle for a signed AAB}';

    /** @var string */
    protected $description = 'Package a signed Android release, refusing to report success unless an artifact exists.';

    private const ARTIFACTS = [
        'release' => 'nativephp/android/app/build/outputs/apk/release/app-release.apk',
        'bundle' => 'nativephp/android/app/build/outputs/bundle/release/app-release.aab',
    ];

    private const PROJECT = 'nativephp/android';

    private const GRADLE = 'nativephp/android/app/build.gradle.kts';

    // A release assemble on a cold CI runner, with no daemon to reuse.
    private const GRADLE_TIMEOUT_SECONDS = 1800;

    public function __construct(
        private readonly Filesystem $files,
        private readonly NativeBuildPatches $patches,
        private readonly Repository $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $buildType = $this->option('build-type');

        if (! is_string($buildType) || ! array_key_exists($buildType, self::ARTIFACTS)) {
            return $this->refuse(
                '--build-type must be one of: '.implode(', ', array_keys(self::ARTIFACTS)).'.',
            );
        }

        $appId = $this->config->get('nativephp.app_id');

        if (! is_string($appId) || $appId === '') {
            return $this->refuse(
                "config('nativephp.app_id') is not a usable bundle id, so there is no identity to "
                .'build against. Set it in the mobile root\'s config/nativephp.php.',
            );
        }

        $failure = $this->appIdPinFailure($appId)
            ?? $this->versionCodeFailure()
            ?? $this->androidProjectFailure();

        if ($failure !== null) {
            return $this->refuse($failure);
        }

        $this->applyGeneratedProjectPatches();

        return $this->package($buildType, $appId);
    }

    private function package(string $buildType, string $appId): int
    {
        $artifact = $this->laravel->basePath(self::ARTIFACTS[$buildType]);

        // native:package reports success on every failure path, so a leftover
        // artifact from an earlier build would satisfy the check below.
        // Removing it first makes the file's presence proof of this run.
        $this->files->delete($artifact);

        $this->call('native:package', [
            'platform' => 'android',
            '--build-type' => $buildType,
        ]);

        if (! $this->files->isFile($artifact) || $this->files->size($artifact) === 0) {
            $this->reportGradleFailure($buildType);

            return $this->refuse(
                "native:package android --build-type={$buildType} produced nothing at {$artifact}.",
            );
        }

        $identityFailure = $this->shippedIdentityFailure($appId)
            ?? $this->shippedVersionCodeFailure();

        if ($identityFailure !== null) {
            return $this->refuse($identityFailure);
        }

        $this->components->info("Packaged {$artifact} as {$appId}");

        return self::SUCCESS;
    }

    // native:package swallows Gradle's output whole: on a failed build it
    // prints "Running Gradle" and then nothing, and returns 0. So the reason is
    // fetched rather than guessed at — one more Gradle run, only ever on the
    // failing path, is worth far more than a list of things it might have been.
    private function reportGradleFailure(string $buildType): void
    {
        $project = $this->laravel->basePath(self::PROJECT);
        $wrapper = $project.DIRECTORY_SEPARATOR.'gradlew';

        if (! $this->files->isFile($wrapper)) {
            $this->components->warn(
                "No {$wrapper}, so Gradle cannot be asked what went wrong. Most often that means "
                .'native:install never produced a project at all.',
            );

            return;
        }

        $this->components->info('Re-running Gradle to recover the error it printed to nobody.');

        $task = $buildType === 'bundle' ? 'bundleRelease' : 'assembleRelease';

        $gradle = new Process([$wrapper, $task, '--stacktrace', '--no-daemon'], $project);
        $gradle->setTimeout(self::GRADLE_TIMEOUT_SECONDS);
        $gradle->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });
    }

    private function appIdPinFailure(string $appId): ?string
    {
        $envPath = $this->laravel->basePath('.env');
        $contents = $this->files->isFile($envPath) ? $this->files->get($envPath) : '';

        if (PinnedAppId::isPinnedIn($contents)) {
            return null;
        }

        return "No usable NATIVEPHP_APP_ID in {$envPath}. native:install reads that file rather than "
            .'config(), and neither a commented nor a blank key satisfies it: it invents '
            .'com.<user>.<random words>, writes that back, and the build ships under it. Add the line '
            ."NATIVEPHP_APP_ID={$appId}.";
    }

    private function androidProjectFailure(): ?string
    {
        $project = $this->laravel->basePath(self::PROJECT);

        if ($this->files->isDirectory($project)) {
            return null;
        }

        // The project is gitignored, and native:install runs only from
        // composer's post-update-cmd, which `composer install` never fires —
        // so a clean checkout reaches native:package with nothing to build.
        $this->components->info('No Android project present; running native:install android.');

        $this->call('native:install', [
            'platform' => 'android',
            '--with-icu' => true,
        ]);

        // The Gradle build file, not the directory again: it is what the
        // identity and version read-backs below both parse, so its absence is
        // the failure that actually matters.
        if ($this->files->isFile($this->laravel->basePath(self::GRADLE))) {
            return null;
        }

        return "native:install android did not create {$project}. That command also returns success "
            .'on every failure path, so its output above is the only account of the cause.';
    }

    private function versionCodeFailure(): ?string
    {
        $configured = $this->config->get('nativephp.version_code');

        // 1 is nativephp/mobile's own package default, which is what resolves
        // whenever NATIVEPHP_APP_VERSION_CODE is unset — as it is on every CI
        // runner, because release.yml exports only NATIVEPHP_APP_VERSION. An
        // APK below the code already on a device is refused as a downgrade.
        if ($configured !== 1 && $configured !== null) {
            return null;
        }

        $version = $this->config->get('nativephp.version');

        if (! is_string($version)) {
            return "config('nativephp.version') is not a string, so no version code can be derived from it.";
        }

        $derived = AndroidVersionCode::fromVersion($version);

        if ($derived === null) {
            return "No usable NATIVEPHP_APP_VERSION_CODE, and none can be derived from version {$version}: "
                .'it is not a plain major.minor.patch with both lower parts under 100. Set the code '
                .'explicitly, or tag a version that can carry one.';
        }

        $this->config->set('nativephp.version_code', $derived);

        $this->components->info("Derived versionCode {$derived} from version {$version}.");

        return null;
    }

    private function shippedVersionCodeFailure(): ?string
    {
        $gradlePath = $this->laravel->basePath(self::GRADLE);

        if (! $this->files->isFile($gradlePath)) {
            return "No {$gradlePath}, so the version code the APK carries cannot be read back.";
        }

        $expected = $this->config->get('nativephp.version_code');
        $actual = AndroidVersionCode::inGradle($this->files->get($gradlePath));

        if ($actual === $expected) {
            return null;
        }

        return sprintf(
            'The APK just built carries versionCode %s, not %s. Play orders releases by that number '
            .'alone, so a wrong one is either refused as a downgrade or spends a number the next '
            .'release needs.',
            $actual ?? 'no readable value',
            is_int($expected) ? $expected : 'the resolved value',
        );
    }

    private function shippedIdentityFailure(string $appId): ?string
    {
        $gradlePath = $this->laravel->basePath(self::GRADLE);

        if (! $this->files->isFile($gradlePath)) {
            return "No {$gradlePath}, so the identity the APK carries cannot be read back.";
        }

        $actual = PinnedAppId::inGradle($this->files->get($gradlePath));

        if ($actual === $appId) {
            return null;
        }

        return sprintf(
            'The APK just built carries applicationId %s, not %s. %s is what the app is published '
            .'under, so a build carrying anything else is a different app to every store and every '
            .'device that already has it installed.',
            $actual ?? 'no readable value',
            $appId,
            $appId,
        );
    }

    private function applyGeneratedProjectPatches(): void
    {
        // CommandStarting never fires for a nested $this->call(), so the
        // provider's listener cannot reach the native:package below. Applying
        // them here is what keeps a CI-built APK equal to a developer's.
        $scripts = NativeBuildPatches::locate($this->laravel->basePath());

        if ($scripts === null) {
            $base = $this->laravel->basePath();

            $this->components->warn(
                "No patch scripts in {$base}/scripts or its parent's. This build ships without the "
                .'camera permission, the file chooser, cookie persistence, shell theming, the boot '
                .'splash and the app icons. A materialized tree needs them copied in beside the app root.',
            );

            return;
        }

        $this->patches->apply($scripts);
    }

    private function refuse(string $reason): int
    {
        $this->components->error($reason);

        return self::FAILURE;
    }
}
