<?php

declare(strict_types=1);

// A green build is not evidence of a clean artefact. The mobile legs have said
// so since a shipped iPhone build carried the build machine's own sync
// identities; the desktop legs said nothing at all, and shipped a vendored
// package's TLS private key for as long as that package has been installed.
//
// This pins the read into every job that produces a desktop bundle, in every
// workflow that produces one, and pins that it refuses rather than reports.

/** @return array<string, string> workflow file name => its text */
function desktopBundlingWorkflows(): array
{
    $found = [];

    foreach (['.github/workflows', '../.github/workflows'] as $candidate) {
        foreach (['release.yml', 'release-build.yml', 'release-mac-app-store.yml'] as $name) {
            $path = base_path($candidate.'/'.$name);

            if (is_file($path)) {
                $found[$name] = (string) file_get_contents($path);
            }
        }
    }

    ksort($found);

    return $found;
}

/**
 * The jobs of one workflow, sliced by indentation rather than parsed: the suite
 * has no YAML reader, and a job header is the only thing at four spaces under
 * `jobs:`. Anything above that key belongs to the trigger block.
 *
 * @return array<string, string> job id => the job's text
 */
function desktopWorkflowJobs(string $workflow): array
{
    $jobs = [];
    $at = strpos($workflow, "\njobs:\n");

    if ($at === false) {
        return $jobs;
    }

    $current = null;

    foreach (explode("\n", substr($workflow, $at)) as $line) {
        if (preg_match('/^ {4}([A-Za-z0-9_-]+):\s*$/', $line, $match) === 1) {
            $current = $match[1];
            $jobs[$current] = '';

            continue;
        }

        if ($current !== null) {
            $jobs[$current] .= $line."\n";
        }
    }

    return $jobs;
}

/**
 * Every job that produces a desktop bundle, named by where its output lands
 * rather than by a list of job names a fourth platform would not be on.
 *
 * @return array<string, string> "workflow:job" => the job's text
 */
function desktopBundleJobs(): array
{
    $jobs = [];

    foreach (desktopBundlingWorkflows() as $name => $workflow) {
        foreach (desktopWorkflowJobs($workflow) as $job => $text) {
            if (str_contains($text, 'nativephp/electron/dist')) {
                $jobs[$name.':'.$job] = $text;
            }
        }
    }

    return $jobs;
}

/**
 * The one step that runs the read, sliced out of its job so a neighbouring
 * step's words cannot answer for it — which they did: the Windows job's
 * signature walk carries the same refusal sentence, and a job-wide check
 * passed with the read's own refusal deleted.
 *
 * A step ends at the first blank line after it, which is how every step in
 * these files is written and the only boundary that does not also swallow the
 * comment block introducing the next one.
 */
function desktopReadStepOf(string $job): string
{
    $at = strpos($job, 'desktop:inspect-bundle');

    if ($at === false) {
        return '';
    }

    $opens = strrpos(substr($job, 0, $at), '- name:');
    $closes = strpos($job."\n\n", "\n\n", $at);

    return $opens === false ? '' : substr($job, $opens, (int) $closes - $opens);
}

it('finds the workflows and the desktop jobs in them', function (): void {
    expect(array_keys(desktopBundlingWorkflows()))
        ->toBe(['release-build.yml', 'release-mac-app-store.yml', 'release.yml']);

    // Three platforms in each of the two bundling workflows, plus the store
    // lane. A count that has fallen is a platform this rule stopped reading.
    expect(desktopBundleJobs())->toHaveCount(7);
});

it('reads every desktop bundle it builds', function (): void {
    $unread = [];

    foreach (desktopBundleJobs() as $job => $text) {
        if (! str_contains($text, 'desktop:inspect-bundle')) {
            $unread[] = $job;
        }
    }

    expect($unread)->toBe([], implode("\n  ", array_merge(
        ['These jobs produce a desktop bundle and nothing reads it:'],
        $unread,
    )));
});

// "Found no bundle" and "found a clean bundle" must not print the same thing.
// That distinction is exactly what let the Android check sit broken.
it('refuses a read that found nothing to read', function (): void {
    $silent = [];

    foreach (desktopBundleJobs() as $job => $text) {
        if (! str_contains(desktopReadStepOf($text), 'nothing was read, so nothing is proven')) {
            $silent[] = $job;
        }
    }

    expect($silent)->toBe([], implode("\n  ", array_merge(
        ['These reads would pass on a build that produced no bundle at all:'],
        $silent,
    )));
});

// An advisory read is a read nobody acts on, and the whole defect this exists
// for is a check that was never able to say no.
it('lets no desktop job excuse the read it runs', function (): void {
    $excused = [];

    foreach (desktopBundleJobs() as $job => $text) {
        foreach (['|| true', 'continue-on-error'] as $escape) {
            if (str_contains($text, $escape)) {
                $excused[] = $job.' carries '.$escape;
            }
        }
    }

    expect($excused)->toBe([], implode("\n  ", array_merge(
        ['A read that cannot fail the job is a read that proves nothing:'],
        $excused,
    )));
});

// release-build.yml exists so a tag can be inspected before anyone commits to
// publishing it, which only means something if it reads the bundle the same
// way. The two drifted once already, over the staged environment.
it('gives the two bundling workflows the same read, character for character', function (): void {
    $workflows = desktopBundlingWorkflows();

    $drifted = [];

    foreach (['macos', 'windows', 'linux'] as $platform) {
        $steps = [];

        foreach (['release.yml', 'release-build.yml'] as $name) {
            $steps[$name] = desktopReadStepOf(desktopWorkflowJobs($workflows[$name])['build-'.$platform] ?? '');
        }

        if ($steps['release.yml'] === '' || $steps['release.yml'] !== $steps['release-build.yml']) {
            $drifted[] = $platform;
        }
    }

    expect($drifted)->toBe([], 'The two workflows read these platforms differently: '.implode(', ', $drifted));
});
