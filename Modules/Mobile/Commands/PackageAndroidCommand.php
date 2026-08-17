<?php

declare(strict_types=1);

namespace Modules\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Modules\Mobile\Internal\Boot\NativeBuildPatches;
use Modules\Mobile\Internal\Boot\PinnedAppId;

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

    public function __construct(
        private readonly Filesystem $files,
        private readonly NativeBuildPatches $patches,
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

        $appId = config('nativephp.app_id');

        if (! is_string($appId) || $appId === '') {
            return $this->refuse(
                "config('nativephp.app_id') is not a usable bundle id, so there is no identity to "
                .'build against. Set it in the mobile root\'s config/nativephp.php.',
            );
        }

        $failure = $this->appIdPinFailure($appId) ?? $this->androidProjectFailure();

        if ($failure !== null) {
            return $this->refuse($failure);
        }

        $this->applyGeneratedProjectPatches();

        return $this->package($buildType, $appId);
    }

    private function package(string $buildType, string $appId): int
    {
        $artifact = base_path(self::ARTIFACTS[$buildType]);

        // native:package reports success on every failure path, so a leftover
        // artifact from an earlier build would satisfy the check below.
        // Removing it first makes the file's presence proof of this run.
        $this->files->delete($artifact);

        $this->call('native:package', [
            'platform' => 'android',
            '--build-type' => $buildType,
        ]);

        if (! $this->files->isFile($artifact) || $this->files->size($artifact) === 0) {
            return $this->refuse(
                "native:package android --build-type={$buildType} produced nothing at {$artifact}. "
                .'That command returns success whatever goes wrong, so its own output above is the '
                .'only account of the cause — most often an absent Android SDK, a Gradle failure, or '
                .'a missing ANDROID_KEYSTORE_FILE / ANDROID_KEYSTORE_PASSWORD / ANDROID_KEY_ALIAS / '
                .'ANDROID_KEY_PASSWORD.',
            );
        }

        $identityFailure = $this->shippedIdentityFailure($appId);

        if ($identityFailure !== null) {
            return $this->refuse($identityFailure);
        }

        $this->components->info("Packaged {$artifact} as {$appId}");

        return self::SUCCESS;
    }

    private function appIdPinFailure(string $appId): ?string
    {
        $envPath = base_path('.env');
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
        $project = base_path(self::PROJECT);

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

        if ($this->files->isDirectory($project)) {
            return null;
        }

        return "native:install android did not create {$project}. That command also returns success "
            .'on every failure path, so its output above is the only account of the cause.';
    }

    private function shippedIdentityFailure(string $appId): ?string
    {
        $gradlePath = base_path(self::GRADLE);

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
        $scripts = dirname(base_path()).DIRECTORY_SEPARATOR.'scripts';

        if (! $this->files->isDirectory($scripts)) {
            $this->components->warn(
                "No patch scripts at {$scripts}. This build ships without the camera permission, "
                .'cookie persistence, shell theming and boot splash patches. A materialized tree '
                .'needs them copied in beside the app root.',
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
