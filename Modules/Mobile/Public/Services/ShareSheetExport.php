<?php

declare(strict_types=1);

namespace Modules\Mobile\Public\Services;

use Illuminate\Container\Container;
use Modules\Core\Public\Enums\Duration;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\OwnerOnlyPath;
use Modules\Mobile\Internal\Native\NativeShareSheet;
use Modules\Mobile\Public\Enums\FileExportOutcome;
use Throwable;

/**
 * @link ../../../../.docs/features/mobile/a-download-the-shell-drops.md
 */
class ShareSheetExport
{
    private const string EXPORT_SUB = 'exports';

    // Long enough that a sheet still reading a file it was handed cannot lose
    // it, short enough that yesterday's whole-database export is gone.

    // Whether a download has to come through here instead of going to the
    // WebView. The Android shell registers no DownloadListener, so an
    // <a download>, a blob URL and a Content-Disposition response are all
    // dropped there with no file, no error and no console entry.
    private static function stagedLifetimeSeconds(): int
    {
        return Duration::Hour->seconds();
    }

    public function replacesWebViewDownload(): bool
    {
        // A shell NativePHP names but MobilePlatform does not model is assumed
        // to drop them too: of the two ways to be wrong about an unknown phone,
        // one costs a share sheet nobody needed and the other is the silence.
        $saves = UserDataPathService::platform()?->savesWebViewDownloads()
            ?? ! UserDataPathService::isMobileRuntime();

        return ! $saves;
    }

    // Safe to call unconditionally — false on web, CI and desktop without
    // ever referencing the native facade.
    public function isAvailable(): bool
    {
        if (! $this->nativeSheet()->isInstalled()) {
            return false;
        }

        return UserDataPathService::isMobileRuntime();
    }

    // A null title or message takes the generic pair. The filename is what
    // tells the reader which export a sheet is offering, so six surfaces
    // spelling their own chrome would only be six chances to drift; the
    // recovery codes pass their own because that sheet says what to do next.
    public function export(
        string $filename,
        string $contents,
        ?string $shareTitle = null,
        ?string $shareMessage = null,
    ): FileExportOutcome {
        if (! $this->isAvailable()) {
            return FileExportOutcome::Unsupported;
        }

        $path = $this->write($filename, $contents);

        return $path === null
            ? FileExportOutcome::Failed
            : $this->handToShareSheet($shareTitle, $shareMessage, $path);
    }

    // The same handover for a payload already on disk. The source is MOVED,
    // not copied: an encrypted database snapshot is the size of the database,
    // and a second one left behind fills a phone the reader cannot sweep out.
    public function exportFile(
        string $sourcePath,
        string $filename,
        ?string $shareTitle = null,
        ?string $shareMessage = null,
    ): FileExportOutcome {
        if (! $this->isAvailable()) {
            return FileExportOutcome::Unsupported;
        }

        $path = $this->move($sourcePath, $filename);

        return $path === null
            ? FileExportOutcome::Failed
            : $this->handToShareSheet($shareTitle, $shareMessage, $path);
    }

    // The handover is a void call and nativephp_call() swallows a name the
    // shell never registered, so making it proves nothing. Asking first is
    // the only way this returns an answer rather than an assumption.
    private function handToShareSheet(?string $shareTitle, ?string $shareMessage, string $path): FileExportOutcome
    {
        if (! $this->canShareFiles()) {
            return FileExportOutcome::Unsupported;
        }

        $shared = $this->share(
            $shareTitle ?? Lang::get('mobile::export.share_title'),
            $shareMessage ?? Lang::get('mobile::export.share_message'),
            $path,
        );

        return $shared ? FileExportOutcome::Shared : FileExportOutcome::Failed;
    }

    // Kept protected, and kept as the two narrowest questions this class asks
    // of the shell, because six modules' tests replace exactly these two to
    // stand in for a phone. Neither takes nor returns a native type, so a
    // double never has to name the package.
    protected function canShareFiles(): bool
    {
        return $this->nativeSheet()->canShareFiles();
    }

    protected function share(string $shareTitle, string $shareMessage, string $path): bool
    {
        return $this->nativeSheet()->file($shareTitle, $shareMessage, $path);
    }

    // Private, and built here rather than injected: an Internal type may not
    // appear on a Public class's own surface, and the subclasses that stand in
    // for a phone declare constructors of their own that would leave an
    // injected one uninitialised.
    private function nativeSheet(): NativeShareSheet
    {
        return new NativeShareSheet;
    }

    // Resolved rather than injected for the same reason nativeSheet() is built
    // here: a constructor on this class would be shadowed by the subclasses
    // that stand in for a phone. The container copy carries the logger, so a
    // mode that will not settle reaches the log instead of a bare null.
    private function ownerOnly(): OwnerOnlyPath
    {
        return Container::getInstance()->make(OwnerOnlyPath::class);
    }

    // Written inside the app's own data directory at 0600, never to shared
    // storage: the share sheet reads it on the user's behalf, so nothing else
    // needs to be able to.
    private function write(string $filename, string $contents): ?string
    {
        try {
            $path = $this->stagingPath($filename);

            // Short-circuit order is the point: the file is made owner-only
            // before a byte of it exists, not narrowed once it is all there.
            if ($path === null
                || ! $this->ownerOnly()->file($path)
                || @file_put_contents($path, $contents) === false) {
                return null;
            }

            return $path;
        } catch (Throwable) {
            return null;
        }
    }

    // rename() across the two directories is a same-filesystem move — both sit
    // under the app's own storage root — so a database-sized payload is not
    // read into PHP memory on a phone just to change where it lives.
    private function move(string $sourcePath, string $filename): ?string
    {
        try {
            $path = $this->stagingPath($filename);

            if ($path === null || ! is_file($sourcePath) || ! @rename($sourcePath, $path)) {
                return null;
            }

            return $this->ownerOnly()->file($path) ? $path : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function stagingPath(string $filename): ?string
    {
        $path = UserDataPathService::appPath(self::EXPORT_SUB.DIRECTORY_SEPARATOR.basename($filename));
        $directory = dirname($path);

        if (! $this->ensureDirectory($directory)) {
            return null;
        }

        $this->pruneStaged($directory);

        return $path;
    }

    // Nothing can delete a staged file after the handover — the OS reads it on
    // the reader's behalf once the call has returned — so the sweep is by age.
    // The reasoning that made exportFile() MOVE its source applies to what it
    // leaves: one encrypted database per download, in a directory no reader sees.
    private function pruneStaged(string $directory): void
    {
        $deadline = time() - self::stagedLifetimeSeconds();

        foreach ((array) glob($directory.DIRECTORY_SEPARATOR.'*') as $staged) {
            $modified = is_string($staged) && is_file($staged) ? @filemtime($staged) : false;

            if ($modified !== false && $modified < $deadline) {
                @unlink($staged);
            }
        }
    }

    private function ensureDirectory(string $directory): bool
    {
        return $this->ownerOnly()->directory($directory);
    }
}
