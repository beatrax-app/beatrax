<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Boot;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;

// Re-applies the generated-project patches immediately before a mobile build.
// They run from composer's hooks, but the build tooling regenerates the
// Android project — so a regenerated project kept whatever the last composer
// run left behind, and the camera and theme fixes vanished from the APK.

// Every script is idempotent and marker-guarded, so running them per build
// costs nothing when they are already applied.
/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
final readonly class NativeBuildPatches
{
    // Generous: these rewrite generated sources on disk and never wait on
    // anything network-bound.
    private const TIMEOUT_SECONDS = 60;

    private const SCRIPTS = [
        'nativephp_grant_webview_camera.php',
        'nativephp_keep_webview_cookies.php',
        'nativephp_theme_native_shell.php',
        'nativephp_brand_boot_splash.php',
        'nativephp_extend_bundle_copy_timeout.php',
        'nativephp_ios_request_body_stream.php',
        'nativephp_ios_download_delegate.php',
    ];

    public function __construct(
        private LoggerInterface $log,
    ) {}

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

            if ($failure !== null) {
                // A failed patch must not abort the build; it degrades to the
                // unpatched shell, which is visible and fixable.
                $this->log->warning('NativeBuildPatches: patch script failed.', [
                    'script' => $script,
                    'exception' => $failure,
                ]);
            }
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
