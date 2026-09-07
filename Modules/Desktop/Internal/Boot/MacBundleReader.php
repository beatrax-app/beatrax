<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Boot;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SimpleXMLElement;
use SplFileInfo;
use Symfony\Component\Process\Process;

// The I/O half of the Mac App Store review. Everything it learns comes from
// the bundle on disk — codesign for the signature and the entitlements, and
// the file magic for what is an executable at all — so a config that claims
// one thing and a package that carries another disagree here.
final class MacBundleReader
{
    private const float TIMEOUT_SECONDS = 30.0;

    /** @return array<string, mixed> */
    public function entitlementsOf(string $path): array
    {
        $plist = $this->output(['codesign', '-d', '--entitlements', '-', '--xml', $path]);

        return $plist === null ? [] : $this->parsePlist($plist);
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

    // A plist rather than a JSON option because codesign has no JSON one, and
    // an <array> value is flattened to its first child: nothing here reads a
    // list, and a nested parser would be reach nothing needs.
    /** @return array<string, mixed> */
    private function parsePlist(string $xml): array
    {
        $document = @simplexml_load_string($xml);

        if (! $document instanceof SimpleXMLElement || ! isset($document->dict)) {
            return [];
        }

        $entitlements = [];
        $key = null;

        foreach ($document->dict->children() as $node) {
            if ($node->getName() === 'key') {
                $key = (string) $node;

                continue;
            }

            if ($key === null) {
                continue;
            }

            $entitlements[$key] = match ($node->getName()) {
                'true' => true,
                'false' => false,
                default => (string) $node,
            };
            $key = null;
        }

        return $entitlements;
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
