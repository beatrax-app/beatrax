<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Process;

// The sidecar the spawner's watcher subshell writes when the artisan child
// exits. It is the only place the exit code of a detached run survives: the
// parent PHP process is long gone by then, and the run card would otherwise
// have nothing to distinguish a clean run from one that blew up.
final class RunExitCodeFile
{
    private const string SUFFIX = '.exit';

    public static function pathFor(string $outPath): string
    {
        return $outPath.self::SUFFIX;
    }

    // Null means "no answer", which covers a run whose watcher was killed and
    // a run spawned before the sidecar existed — never "exited cleanly".
    public static function read(string $outPath): ?int
    {
        $path = self::pathFor($outPath);
        if (! is_file($path)) {
            return null;
        }

        $raw = trim((string) @file_get_contents($path));

        return preg_match('/^\d{1,3}$/', $raw) === 1 ? (int) $raw : null;
    }
}
