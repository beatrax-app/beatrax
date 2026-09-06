<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Boot;

use Modules\Core\Public\Services\UserDataPathService;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

// What a built mobile artifact actually carries, read out of the artifact
// rather than out of the rules meant to keep things out of it. An exclusion
// list is a claim about a build; this is the build.
final readonly class ShippedBundleContents
{
    // Key material, whichever shape it takes. A keystore in the bundle is the
    // release-signing identity handed to anyone who unzips a public download.
    private const array SECRET_EXTENSIONS = ['jks', 'keystore', 'p12', 'pfx', 'pem', 'p8', 'mobileprovision', 'key'];

    // A file holding a ledger. The phone runs every migration on first launch
    // and ships no database at all, so any of these is the builder's own.
    private const array DATA_EXTENSIONS = ['sqlite', 'sqlite3', 'db'];

    // Read as a prefix, so ANDROID_KEYSTORE_PASSWORD and ANDROID_KEY_ALIAS are
    // both covered by the two entries their names begin with.
    private const array SECRET_ENV_PREFIXES = [
        'ANDROID_KEYSTORE_',
        'ANDROID_KEY_',
        'APP_STORE_API_',
        'DO_SPACES_',
        'BIFROST_',
        'AWS_SECRET',
        'GITHUB_TOKEN',
    ];

    // Every refusal the artifact earns, and an empty list when it earns none.
    /**
     * @return list<string>
     */
    public function refusals(string $path): array
    {
        // The seam question, asked before the class that needs the extension is
        // reached. The mobile PHP build carries no ext-zip, and this reports
        // that rather than dying on it — an artifact nothing could open has not
        // been shown to be clean.
        if (! class_exists(ZipArchive::class)) {
            return ['no ext-zip in this PHP build, so the artifact was never read: '.$path];
        }

        $unpacked = $this->unpack($path);

        if ($unpacked === null) {
            return ['not an archive this can read: '.$path];
        }

        $refusals = [];

        foreach ($this->everyFile($unpacked) as $file) {
            $relative = substr($file->getPathname(), strlen($unpacked) + 1);
            $extension = strtolower($file->getExtension());

            if (in_array($extension, self::SECRET_EXTENSIONS, true)) {
                $refusals[] = 'key material: '.$relative;

                continue;
            }

            if (in_array($extension, self::DATA_EXTENSIONS, true)) {
                $refusals[] = 'a database: '.$relative;

                continue;
            }

            foreach ($this->secretAssignments($file) as $name) {
                $refusals[] = 'a secret in '.$relative.': '.$name;
            }
        }

        sort($refusals);

        return $refusals;
    }

    // An assignment with a value, never a bare name: a stripped key that is
    // still mentioned in a comment, or left as `KEY=`, carries nothing.
    /**
     * @return list<string>
     */
    private function secretAssignments(SplFileInfo $file): array
    {
        if ($file->getSize() > 512_000 || ! $this->readsAsText($file)) {
            return [];
        }

        $found = [];

        foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            foreach (self::SECRET_ENV_PREFIXES as $prefix) {
                $at = strpos($line, '=');

                if (! str_starts_with($line, $prefix) || $at === false) {
                    continue;
                }

                if (trim(substr($line, $at + 1), " \t\"'") !== '') {
                    $found[] = substr($line, 0, $at);
                }
            }
        }

        return $found;
    }

    private function readsAsText(SplFileInfo $file): bool
    {
        $handle = @fopen($file->getPathname(), 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 4096);
        fclose($handle);

        return ! str_contains($head, "\0");
    }

    /**
     * @return list<SplFileInfo>
     */
    private function everyFile(string $directory): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile()) {
                $files[] = $file;
            }
        }

        return $files;
    }

    // Nested archives are unpacked too: the PHP application travels inside the
    // artifact as its own zip, so a scan that stopped at the outer entries
    // would read the wrapper and never the tree the wrapper carries.
    private function unpack(string $path, int $depth = 0): ?string
    {
        $zip = new ZipArchive;

        if ($depth > 2 || $zip->open($path) !== true) {
            return null;
        }

        // Not the shared temp directory: /tmp is 1777, so an unpacked bundle
        // leaks every entry's name and size to anyone on the machine — and the
        // entries are exactly what this is looking for.
        $target = UserDataPathService::appPath('tmp-inspect-bundle/'.bin2hex(random_bytes(8)));
        mkdir($target, 0700, true);

        $zip->extractTo($target);
        $zip->close();

        foreach ($this->everyFile($target) as $file) {
            if (! in_array(strtolower($file->getExtension()), ['zip', 'aab', 'apk', 'ipa'], true)) {
                continue;
            }

            $inner = $this->unpack($file->getPathname(), $depth + 1);

            if ($inner !== null) {
                rename($inner, $file->getPathname().'.unpacked');
            }
        }

        return $target;
    }
}
