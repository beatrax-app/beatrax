<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\BackendSourceFiles;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md
 */

/**
 * Short class name => fully-qualified name, read off the file's own imports.
 *
 * @return array<string, string>
 */
function synchronousDispatchImports(string $source): array
{
    $imports = [];

    foreach (PatternScan::sets('/^use\s+([A-Za-z0-9_\\\\]+);/m', $source) as $set) {
        $fqcn = $set[1];
        $short = substr((string) strrchr('\\'.$fqcn, '\\'), 1);
        $imports[$short] = $fqcn;
    }

    return $imports;
}

/**
 * Every class named at a dispatchSync() call in one file, resolved where the
 * file's imports allow it. An unresolvable name is kept verbatim so it reads
 * as an offender rather than disappearing from the count.
 *
 * @return list<string>
 */
function synchronouslyDispatchedJobs(string $source): array
{
    $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source) ?? $source;
    $imports = synchronousDispatchImports($stripped);

    $names = [];

    foreach (PatternScan::sets('/([A-Za-z0-9_\\\\]+)::dispatchSync\s*\(/', $stripped) as $set) {
        $names[] = $set[1];
    }

    foreach (PatternScan::sets('/dispatchSync\s*\(\s*new\s+([A-Za-z0-9_\\\\]+)/', $stripped) as $set) {
        $names[] = $set[1];
    }

    return array_values(array_unique(array_map(
        static fn (string $name): string => $imports[$name] ?? $name,
        $names,
    )));
}

/**
 * Every "file — class" the scan resolved, offenders flagged. Both halves are
 * returned so the test can prove the scan read the tree before reading the
 * empty offender list as "clean".
 *
 * @return array{seen: list<string>, offenders: list<string>}
 */
function synchronousDispatchSites(): array
{
    $seen = [];
    $offenders = [];

    foreach (BackendSourceFiles::all() as $path) {
        $source = (string) file_get_contents($path);

        if (! str_contains($source, 'dispatchSync')) {
            continue;
        }

        foreach (synchronouslyDispatchedJobs($source) as $job) {
            $site = str_replace(base_path().'/', '', $path).' — '.$job;
            $seen[] = $site;

            if (! class_exists($job) || ! is_a($job, ShouldQueue::class, allow_string: true)) {
                $offenders[] = $site;
            }
        }
    }

    return ['seen' => $seen, 'offenders' => $offenders];
}

// Bus\Dispatcher::dispatchSync() routes through SyncQueue — the only thing in
// the framework that raises JobFailed — ONLY when the command is a ShouldQueue.
// Anything else goes to dispatchNow(), which raises no queue event at all, so a
// listener repairing a run row would never hear that the work failed. Measured
// against laravel/framework, not assumed: SyncQueue::handleException() fails the
// job and rethrows, and Job::fail() dispatches JobFailed in a finally.
it('dispatches synchronously only jobs whose failure a JobFailed listener can hear', function (): void {
    $sites = synchronousDispatchSites();

    expect($sites['seen'])->not->toBe(
        [],
        'The scan resolved no dispatchSync() call at all, so an empty offender list means it stopped reading the tree rather than finding it clean.',
    );

    expect($sites['offenders'])->toBe(
        [],
        "A command dispatched with dispatchSync() that is not a ShouldQueue never reaches SyncQueue, so no\n".
        "JobFailed is raised for it and any row a listener would have moved out of pending/running stays there.\n".
        "Make the job a ShouldQueue, or have the caller own the failure itself.\n  ".
        implode("\n  ", $sites['offenders']),
    );
});
