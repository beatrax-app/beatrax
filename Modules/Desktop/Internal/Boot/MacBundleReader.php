<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Boot;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;

// The I/O half of the Mac App Store review. Everything it learns comes from
// the bundle on disk — codesign for the signature and the entitlements, and
// the file magic for what is an executable at all — so a config that claims
// one thing and a package that carries another disagree here.
final class MacBundleReader implements ReadsAMacBundle
{
    private const float TIMEOUT_SECONDS = 30.0;

    /** @return array<string, mixed> */
    public function entitlementsOf(string $path): array
    {
        $plist = $this->output(['codesign', '-d', '--entitlements', '-', '--xml', $path]);

        return $plist === null ? [] : EntitlementsPlist::parse($plist);
    }

    public function isSigned(string $path): bool
    {
        return $this->output(['codesign', '--verify', '--strict', $path]) !== null;
    }

    // Executable by the file magic rather than by the permission bit: a shell
    // script is +x and is not what a signature or a MacOS directory is about,
    // and a Mach-O with the bit cleared still ships as one.
    /** @return list<string> paths relative to the bundle */
    public function executablesIn(string $bundlePath): array
    {
        $found = [];

        foreach ($this->everyFileIn($bundlePath) as $path) {
            $magic = $this->output(['file', '-b', $path]) ?? '';

            if (str_contains($magic, 'Mach-O') && str_contains($magic, 'executable')) {
                $found[] = ltrim(str_replace($bundlePath, '', $path), '/');
            }
        }

        sort($found);

        return $found;
    }

    // A nested executable has to sit in SOME Contents/MacOS — the app's own,
    // or that of a bundle nested inside it. Anything else is refused by the
    // submission tooling before a reviewer sees it.
    public function isInAMacOsDirectory(string $relativePath): bool
    {
        return str_contains('/'.$relativePath, '/Contents/MacOS/');
    }

    // A _MASReceipt is the only trustworthy signal a bundle came from the
    // store: it is written at install by the store, never by the build.
    /** @return array<string, array{from_the_store: bool, electron: bool}> */
    public function installedBundles(): array
    {
        $installed = glob('/Applications/*.app');
        $bundles = [];

        foreach ($installed === false ? [] : $installed as $path) {
            $bundles[$path] = [
                'from_the_store' => is_dir($path.'/Contents/_MASReceipt'),
                'electron' => is_dir($path.'/Contents/Frameworks/Electron Framework.framework'),
            ];
        }

        return $bundles;
    }

    /** @return list<string> */
    private function everyFileIn(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $paths = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }

    // Null means the tool refused, which for `codesign --verify` IS the
    // answer. An empty string is a successful run that printed nothing, and
    // the two must not collapse: a bundle with no entitlements at all is a
    // finding, not a read failure.
    /** @param list<string> $argv */
    private function output(array $argv): ?string
    {
        $process = new Process($argv);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }
}
