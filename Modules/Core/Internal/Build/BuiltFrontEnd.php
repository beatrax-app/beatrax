<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Build;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

// public/build is what ships, and nothing between a checkout and a device
// rebuilds it: both shells bundle the directory exactly as they find it on
// disk, while the manifest still resolves and every view still renders.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-built-front-end-older-than-the-sources-it-was-compiled-from
 */
final readonly class BuiltFrontEnd
{
    // Vite's three entries and the two files that decide what it makes of
    // them, plus the Blade the Tailwind pass compiles the stylesheet from: a
    // utility class first written into a template is absent from the built
    // sheet until the next build, which is how six screens lost their inset.
    private const array COMPILED_FROM = [
        'vite.config.js',
        'package.json',
        'package-lock.json',
        'resources/js',
        'resources/css',
        'resources/brand',
        'resources/views',
        'Modules/*/Resources/views',
    ];

    private function __construct(private string $root, private string $buildDirectory) {}

    // The tree public/ resolves into rather than base_path(): the mobile
    // Composer root reaches this one by symlink, so asking there for
    // vite.config.js finds nothing while the bundle it builds is compiled
    // from the sources here.
    public static function beside(string $publicDirectory): self
    {
        $resolved = realpath($publicDirectory);
        $public = $resolved === false ? $publicDirectory : $resolved;

        return new self(dirname($public), $public.'/build');
    }

    public function staleness(): ?StaleFrontEnd
    {
        $source = $this->newestSource();

        if ($source === null) {
            return null;
        }

        $built = $this->newestBuilt();

        if ($built !== null && $built['modified'] >= $source['modified']) {
            return null;
        }

        return new StaleFrontEnd(
            $this->relative($source['path']),
            $source['modified'],
            $built === null ? null : $this->relative($built['path']),
            $built['modified'] ?? null,
        );
    }

    /**
     * @return array<string, int>
     */
    public function sources(): array
    {
        $files = [];

        foreach (self::COMPILED_FROM as $pattern) {
            foreach ((array) glob($this->root.'/'.$pattern) as $match) {
                $files += $this->modificationTimes((string) $match);
            }
        }

        return $files;
    }

    /**
     * @return array{path: string, modified: int}|null
     */
    private function newestSource(): ?array
    {
        return self::newest($this->sources());
    }

    /**
     * @return array{path: string, modified: int}|null
     */
    private function newestBuilt(): ?array
    {
        return self::newest($this->modificationTimes($this->buildDirectory));
    }

    /**
     * @param  array<string, int>  $files
     * @return array{path: string, modified: int}|null
     */
    private static function newest(array $files): ?array
    {
        $newest = null;

        foreach ($files as $path => $modified) {
            if ($newest === null || $modified > $newest['modified']) {
                $newest = ['path' => $path, 'modified' => $modified];
            }
        }

        return $newest;
    }

    /**
     * @return array<string, int>
     */
    private function modificationTimes(string $path): array
    {
        if (is_file($path)) {
            return [$path => (int) filemtime($path)];
        }

        if (! is_dir($path)) {
            return [];
        }

        return $this->walk($path);
    }

    /**
     * @return array<string, int>
     */
    private function walk(string $directory): array
    {
        $files = [];
        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($tree as $file) {
            if ($file->isFile()) {
                $files[$file->getPathname()] = $file->getMTime();
            }
        }

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace($this->root.'/', '', $path);
    }
}
