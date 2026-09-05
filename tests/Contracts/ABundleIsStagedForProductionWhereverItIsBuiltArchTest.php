<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// Workflows that produce an installable bundle. release.yml publishes one;
// release-build.yml exists so a tag can be built and inspected before anyone
// commits to publishing it, which only means something if the two stage the
// same environment.
// Byte-sorted, so the discovered list can be compared without reordering it.
const BUNDLING_WORKFLOWS = ['release-build.yml', 'release.yml'];

// Every other .env staging in CI wants the development values: those jobs run
// the suite, and APP_ENV=testing is what the suite reads.
const STAGES_A_BUNDLE = '/\b(native:build|native:package|mobile:package-)/';

/**
 * @return list<string>
 */
function bundlingWorkflowFiles(): array
{
    foreach (['.github/workflows', '../.github/workflows'] as $candidate) {
        $directory = base_path($candidate);

        if (! is_dir($directory)) {
            continue;
        }

        return array_values(array_filter(
            (array) glob($directory.'/*.yml'),
            static fn (string $file): bool => in_array(basename($file), BUNDLING_WORKFLOWS, true),
        ));
    }

    return [];
}

/**
 * @return array<string, string>
 */
function workflowJobs(string $source): array
{
    $lines = explode("\n", $source);
    $jobs = [];
    $current = null;

    foreach ($lines as $line) {
        $named = PatternScan::first('/^ {4}([A-Za-z0-9_-]+):\s*$/', $line);

        if ($named !== [] && str_starts_with($line, '    ') && ! str_starts_with($line, '     ')) {
            $current = $named[1];
            $jobs[$current] = '';

            continue;
        }

        if ($current !== null) {
            $jobs[$current] .= $line."\n";
        }
    }

    return $jobs;
}

it('names every workflow that builds a bundle', function (): void {
    foreach (['.github/workflows', '../.github/workflows'] as $candidate) {
        $directory = base_path($candidate);

        if (! is_dir($directory)) {
            continue;
        }

        $bundling = [];

        foreach ((array) glob($directory.'/*.yml') as $file) {
            $source = (string) file_get_contents((string) $file);

            // ci.yml only mentions native:build in a comment explaining which
            // PHP the release runs on, so the match has to be a real step.
            $body = PatternScan::replace('/^\s*#.*$/m', '', $source);

            if (PatternScan::matches(STAGES_A_BUNDLE, $body)) {
                $bundling[] = basename((string) $file);
            }
        }

        sort($bundling);

        expect($bundling)->toBe(BUNDLING_WORKFLOWS, implode("\n", [
            'A workflow that builds an installable bundle has to stage the',
            'environment that bundle ships with. The list above is the scope',
            'the case below checks; a workflow that has started building one',
            'and is not on it would go unchecked.',
        ]));

        return;
    }

    throw new RuntimeException('No .github/workflows directory from either composer root.');
});

it('overrides the development environment in every job that builds a bundle', function (): void {
    $files = bundlingWorkflowFiles();

    expect($files)->not->toBeEmpty();

    $offenders = [];

    foreach ($files as $file) {
        foreach (workflowJobs((string) file_get_contents($file)) as $job => $body) {
            if (! PatternScan::matches(STAGES_A_BUNDLE, PatternScan::replace('/^\s*#.*$/m', '', $body))) {
                continue;
            }

            $lines = explode("\n", $body);

            foreach ($lines as $number => $line) {
                if (! str_contains($line, 'cp .env.example .env')) {
                    continue;
                }

                if (str_contains(implode("\n", array_slice($lines, $number, 8)), 'APP_ENV=production')) {
                    continue;
                }

                $offenders[] = basename($file).' job '.$job.', line '.($number + 1).' of it';
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These steps stage .env.example into a job that goes on to build a',
        'bundle, and leave it there:',
        ...$offenders,
        '',
        'That file carries APP_ENV=local and APP_DEBUG=true. DevConsoleBuildGate',
        'reads app.env first and answers local as a development build, so the',
        'artisan runner, the query panel and the log tail open with no key --',
        'and on a phone the first account is the only account. Laravel also',
        'answers an error with the debug page, which prints file paths, the',
        'framework version and a full stack trace.',
        '',
        'A job that only runs the suite is not an offender: the suite wants the',
        'development values. release.yml rewrites both keys at every one of its',
        'four bundle-staging steps. Copy that block rather than writing a new one.',
    ]));
});

// verify-signature refuses a macOS artefact it cannot name: an unverifiable
// identity is treated exactly like a wrong one, so an invocation that omits
// the input does not skip the check, it fails the job.
it('hands the macOS signature check an identifier to check against', function (): void {
    $files = bundlingWorkflowFiles();

    expect($files)->not->toBeEmpty();

    $offenders = [];

    foreach ($files as $file) {
        $lines = explode("\n", (string) file_get_contents($file));

        foreach ($lines as $number => $line) {
            if (! str_contains($line, 'uses: ./.github/actions/verify-signature')) {
                continue;
            }

            $window = implode("\n", array_slice($lines, $number, 6));

            if (! str_contains($window, 'platform: macos')) {
                continue;
            }

            if (str_contains($window, 'expected-identifier:')) {
                continue;
            }

            $offenders[] = basename($file).':'.($number + 1);
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These steps verify a macOS bundle without telling the action which',
        'identifier the bundle must declare:',
        ...$offenders,
        '',
        'The action fails outright on an empty expected-identifier, so this is',
        'not a weaker check -- it is a job that cannot pass. release.yml carried',
        'it while release-build.yml did not, which meant the publishing pipeline',
        'was the broken one and the workflow that only produces artefacts for',
        'inspection was the strict one.',
        '',
        'Resolve it from the build with `config(\'nativephp.app_id\')` rather than',
        'writing the string twice: codesign prints an Identifier for whatever it',
        'was handed, so a bundle notarised under the framework scaffold id is a',
        'correctly signed lie.',
    ]));
});
