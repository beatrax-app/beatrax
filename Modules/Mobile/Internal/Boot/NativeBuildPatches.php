<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Boot;

use Modules\Mobile\Internal\Exceptions\NativeBuildPatchException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

// Re-applies the generated-project patches immediately before a mobile build.
// They run from composer's hooks, but the build tooling regenerates the
// Android project — so a regenerated project kept whatever the last composer
// run left behind, and the camera and theme fixes vanished from the APK.

// Every script is idempotent and marker-guarded, so running them per build
// costs nothing when they are already applied.
final readonly class NativeBuildPatches
{
    // Generous: these rewrite generated sources on disk and never wait on
    // anything network-bound.
    private const TIMEOUT_SECONDS = 60;

    // Every patch composer applies, because every build regenerates the project
    // that carries them. NativeBuildPatchCoverageTest holds the two lists
    // together — the file chooser was in composer's and not in this one, and a
    // CI-built APK would have shipped with no way to take a file in at all.
    private const SCRIPTS = [
        'nativephp_grant_webview_camera.php',
        'nativephp_android_file_chooser.php',
        'nativephp_keep_webview_cookies.php',
        'nativephp_exclude_data_from_backup.php',
        'nativephp_strip_unused_permissions.php',
        'nativephp_theme_native_shell.php',
        'nativephp_brand_boot_splash.php',
        'nativephp_android_adaptive_icon.php',
        'nativephp_ios_app_icon.php',
        'nativephp_extend_bundle_copy_timeout.php',
        'nativephp_ios_request_body_stream.php',
        'nativephp_ios_download_delegate.php',
        'nativephp_ios_local_network_discovery.php',
        'nativephp_ios_privacy_manifest.php',
        'nativephp_ios_export_compliance.php',
    ];

    // A cosmetic patch that fails degrades to the unpatched shell, which is
    // visible on the device. These two are invisible until App Store review
    // rejects the build, so they stop it here instead.
    private const REQUIRED_SCRIPTS = [
        'nativephp_ios_privacy_manifest.php',
        'nativephp_ios_export_compliance.php',
    ];

    public function __construct(
        private LoggerInterface $log,
    ) {}

    // Where the patch scripts are, which is not one place: the dev mobile root
    // reaches them at ../scripts, while a Bifrost tree is materialized FROM
    // that root, so ../ climbs out of the build repo. Probed by content —
    // both roots have a scripts/ directory, only one has these in it.
    public static function locate(string $basePath): ?string
    {
        $probe = self::SCRIPTS[0];

        foreach ([$basePath, dirname($basePath)] as $root) {
            $candidate = $root.DIRECTORY_SEPARATOR.'scripts';

            if (is_file($candidate.DIRECTORY_SEPARATOR.$probe)) {
                return $candidate;
            }
        }

        return null;
    }

    // $scriptsDirectory is the repository's scripts/ folder. Missing scripts
    // are skipped: this same provider boots from roots that do not ship them.
    public function apply(string $scriptsDirectory): void
    {
        foreach (self::SCRIPTS as $script) {
            $path = $scriptsDirectory.DIRECTORY_SEPARATOR.$script;

            if (! is_file($path)) {
                continue;
            }

            $failure = $this->runPatch($path);

            if ($failure === null) {
                continue;
            }

            if (in_array($script, self::REQUIRED_SCRIPTS, true)) {
                throw NativeBuildPatchException::requiredScriptFailed($script, $failure);
            }

            $this->log->warning('NativeBuildPatches: patch script failed.', [
                'script' => $script,
                'exception' => $failure,
            ]);
        }
    }

    // The reason the patch did not apply, or null when it did. Each script
    // ends in exit(0), so requiring one would end the BUILD process with it —
    // the first patch ran and nothing was ever compiled. A child process
    // contains both that exit and any fatal inside the script.
    private function runPatch(string $path): ?string
    {
        try {
            $process = new Process([PHP_BINARY, $path]);
            $process->setTimeout(self::TIMEOUT_SECONDS);
            $process->run();

            return $process->isSuccessful() ? null : trim($process->getErrorOutput());
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
