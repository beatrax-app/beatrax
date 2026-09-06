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

// The composite action is one bash script under a `run:` key. Lifted out by
// indentation rather than parsed as YAML, which the suite has no reader for.
function shippedEnvScript(string $action): string
{
    $lines = explode("\n", $action);
    $body = [];
    $indent = null;

    foreach ($lines as $number => $line) {
        if ($indent === null) {
            if (PatternScan::matches('/^\s+run: \|\s*$/', $line)) {
                $indent = strspn((string) ($lines[$number + 1] ?? ''), ' ');
            }

            continue;
        }

        if (trim($line) !== '' && strspn($line, ' ') < $indent) {
            break;
        }

        $body[] = substr($line, $indent);
    }

    return implode("\n", $body);
}

function shippedEnvRun(string $script, string $directory, string $extra): int
{
    $path = $directory.'/run.sh';
    file_put_contents($path, $script);

    $command = 'cd '.escapeshellarg($directory)
        .' && EXTRA='.escapeshellarg($extra)
        .' bash '.escapeshellarg($path).' 2>&1';

    exec($command, $output, $status);

    return $status;
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

const SHIPPED_ENV_ACTION = '.github/actions/stage-shipped-env';

// The one spelling both workflows reach for. A job may still write the
// override inline -- what it may not do is build a bundle having done neither.
it('overrides the development environment in every job that builds a bundle', function (): void {
    $files = bundlingWorkflowFiles();

    expect($files)->not->toBeEmpty();

    $unstaged = [];
    $staged = 0;

    foreach ($files as $file) {
        foreach (workflowJobs((string) file_get_contents($file)) as $job => $body) {
            if (! PatternScan::matches(STAGES_A_BUNDLE, PatternScan::replace('/^\s*#.*$/m', '', $body))) {
                continue;
            }

            if (str_contains($body, 'uses: ./'.SHIPPED_ENV_ACTION)) {
                $staged++;

                continue;
            }

            $lines = explode("\n", $body);
            $overridden = false;

            foreach ($lines as $number => $line) {
                if (str_contains($line, 'cp .env.example .env')
                    && str_contains(implode("\n", array_slice($lines, $number, 8)), 'APP_ENV=production')) {
                    $overridden = true;
                }
            }

            if ($overridden) {
                $staged++;

                continue;
            }

            $unstaged[] = basename($file).' job '.$job;
        }
    }

    // Named positively rather than counting offenders: a job that stages
    // nothing at all is the case the old shape of this rule could not see,
    // because it looked for a copy that a job doing nothing never makes.
    expect($staged)->toBeGreaterThan(4);

    expect($unstaged)->toBe([], implode("\n", [
        'These jobs build an installable bundle without overriding the',
        'development environment it ships with:',
        ...$unstaged,
        '',
        '.env.example carries APP_ENV=local and APP_DEBUG=true. DevConsoleBuildGate',
        'reads app.env first and answers local as a development build, so the',
        'artisan runner, the query panel and the log tail open with no key --',
        'and on a phone the first account is the only account. Laravel also',
        'answers an error with the debug page, which prints file paths, the',
        'framework version and a full stack trace.',
        '',
        'A job that only runs the suite is not an offender: the suite wants the',
        'development values. Reach for the composite action rather than writing',
        'the rewrite again: uses: ./'.SHIPPED_ENV_ACTION,
    ]));
});

// The rule above is satisfied by a step that names the action, so what the
// action does is the whole of what it enforces. Run rather than read: a
// substring check passes on the words appearing anywhere, including inside the
// read-back that was meant to prove the write happened.
it('keeps the override inside the action both workflows name, and proves it by running it', function (): void {
    $action = base_path(SHIPPED_ENV_ACTION.'/action.yml');

    if (! is_file($action)) {
        $action = base_path('../'.SHIPPED_ENV_ACTION.'/action.yml');
    }

    expect(is_file($action))->toBeTrue(
        'Both bundling workflows delegate the shipped .env to '.SHIPPED_ENV_ACTION.', so it has to exist.',
    );

    $script = shippedEnvScript((string) file_get_contents($action));

    expect($script)->toContain('.env.bundled');

    $directory = sys_get_temp_dir().'/beatrax-shipped-env-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o755, true);

    try {
        file_put_contents($directory.'/.env.bundled', implode("\n", [
            'APP_NAME=Beatrax',
            'APP_ENV=local',
            'APP_DEBUG=true',
            'DB_CONNECTION=sqlite',
            '',
        ]));

        $status = shippedEnvRun($script, $directory, "AUTO_UPDATE_FEED_URL=https://example.test/feed.json\n");

        expect($status)->toBe(0, 'The action exits non-zero on its own read-back, so this is what a bundling job would have got.');

        $staged = (string) file_get_contents($directory.'/.env');

        expect($staged)->toContain('APP_ENV=production')
            ->and($staged)->toContain('APP_DEBUG=false')
            ->and($staged)->not->toContain('APP_ENV=local')
            ->and($staged)->not->toContain('APP_DEBUG=true')
            // Not a rewrite of the whole template: everything else the file
            // carries has to survive, or the bundle ships without its database.
            ->and($staged)->toContain('DB_CONNECTION=sqlite')
            ->and($staged)->toContain('APP_NAME=Beatrax')
            // The extra lines are how the desktop legs hand the updater its
            // feed, and they are written after the override, not instead of it.
            ->and($staged)->toContain('AUTO_UPDATE_FEED_URL=https://example.test/feed.json');

        // Read rather than run, and deliberately: the only way to reach this arm is
        // to break the write above it, which the case would then already be failing
        // on. What it is worth is that a renamed key in the template stops the
        // build rather than shipping a bundle nobody looked at.
        expect($script)->toContain('grep -qxF');
    } finally {
        foreach ((array) glob($directory.'/{,.}[!.,!..]*', GLOB_BRACE) as $file) {
            @unlink((string) $file);
        }
        @rmdir($directory);
    }
});

// "Nothing survived the filter" is grep's exit 1, and under `set -e` that ends
// the job with no message — from a template that is merely small. The case
// above cannot see it, because a run that aborts early is non-zero too.
it('stages a template made only of the keys it replaces', function (): void {
    $action = base_path(SHIPPED_ENV_ACTION.'/action.yml');

    if (! is_file($action)) {
        $action = base_path('../'.SHIPPED_ENV_ACTION.'/action.yml');
    }

    $script = shippedEnvScript((string) file_get_contents($action));
    $directory = sys_get_temp_dir().'/beatrax-shipped-env-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o755, true);

    try {
        file_put_contents($directory.'/.env.bundled', "APP_ENV=local\nAPP_DEBUG=true\n");

        expect(shippedEnvRun($script, $directory, ''))->toBe(0)
            ->and((string) file_get_contents($directory.'/.env'))->toContain('APP_ENV=production');
    } finally {
        @unlink($directory.'/.env.bundled');
        @unlink($directory.'/.env');
        @unlink($directory.'/run.sh');
        @rmdir($directory);
    }
});

// An `extra` line whose value never arrived is the shape that survives every
// later check: the key is present, the bundle boots, and the feed the updater
// polls is the empty string. Refused where it is still nameable.
it('refuses an extra line whose value never arrived', function (): void {
    $action = base_path(SHIPPED_ENV_ACTION.'/action.yml');

    if (! is_file($action)) {
        $action = base_path('../'.SHIPPED_ENV_ACTION.'/action.yml');
    }

    $script = shippedEnvScript((string) file_get_contents($action));
    $directory = sys_get_temp_dir().'/beatrax-shipped-env-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o755, true);

    try {
        // A template of nothing but the two keys being replaced: grep answers
        // "no lines survived" there, which is not an error and must not read
        // as one, or this case passes on a refusal it did not cause.
        file_put_contents($directory.'/.env.bundled', "APP_ENV=local\nAPP_DEBUG=true\n");

        expect(shippedEnvRun($script, $directory, "AUTO_UPDATE_FEED_URL=\n"))->not->toBe(
            0,
            'An unset workflow variable interpolates to exactly this, and a bundle carrying '
            .'AUTO_UPDATE_FEED_URL= polls the empty string for its signed manifest.',
        );
    } finally {
        @unlink($directory.'/.env.bundled');
        @unlink($directory.'/.env');
        @unlink($directory.'/run.sh');
        @rmdir($directory);
    }
});

// A template that no longer carries the keys the action rewrites is the silent
// failure the read-back exists for, so the refusal is asserted as well.
it('refuses a template it cannot stage, rather than shipping it', function (): void {
    $action = base_path(SHIPPED_ENV_ACTION.'/action.yml');

    if (! is_file($action)) {
        $action = base_path('../'.SHIPPED_ENV_ACTION.'/action.yml');
    }

    $script = shippedEnvScript((string) file_get_contents($action));
    $directory = sys_get_temp_dir().'/beatrax-shipped-env-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o755, true);

    try {
        expect(shippedEnvRun($script, $directory, ''))->not->toBe(
            0,
            'No .env.bundled at all is the clearest form of "there is nothing to ship from".',
        );
    } finally {
        @unlink($directory.'/.env');
        @rmdir($directory);
    }
});

// Every Composer root that builds a bundle carries the reviewed template the
// action stages, and the reviewed template holds every key the development one
// does. A key added to `.env.example` and not here is a key the bundle ships
// on the framework's default, silently — which is how the four below shipped.
const BUNDLED_TEMPLATE_ROOTS = ['.', 'mobile-app'];

// Keys the reviewed template deliberately does not carry, each with why.
const BUNDLED_TEMPLATE_OMITS = [
    // Read by nothing: no import.meta.env reference anywhere in the tree, on
    // either root. Laravel scaffolding, not a value this product ships.
    'VITE_APP_NAME' => 'nothing reads it — grep import.meta.env across resources/ before restoring it',
];

/** @return array<string, string> the KEY=value pairs a template declares */
function bundledTemplateKeys(string $path): array
{
    $keys = [];

    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
        $pair = PatternScan::first('/^([A-Z][A-Z0-9_]*)=(.*)$/', trim($line));

        if ($pair !== []) {
            $keys[$pair[1]] = $pair[2];
        }
    }

    return $keys;
}

function bundledTemplateRoot(string $root): string
{
    $desktop = is_file(base_path('.github/workflows/release.yml')) ? base_path() : base_path('..');

    return $root === '.' ? $desktop : $desktop.'/'.$root;
}

it('gives every root that builds a bundle a reviewed template of its own', function (): void {
    $missing = [];

    foreach (BUNDLED_TEMPLATE_ROOTS as $root) {
        if (! is_file(bundledTemplateRoot($root).'/.env.bundled')) {
            $missing[] = $root.'/.env.bundled';
        }
    }

    expect($missing)->toBe([], implode("\n", [
        'The staging action stages `.env.bundled` and fails without one, so a root',
        'that builds a bundle and does not carry one cannot be built at all:',
        ...$missing,
    ]));
});

it('leaves no key in the development template that the bundle would ship on a default', function (): void {
    $unshipped = [];

    foreach (BUNDLED_TEMPLATE_ROOTS as $root) {
        $path = bundledTemplateRoot($root);
        $bundled = bundledTemplateKeys($path.'/.env.bundled');
        $example = bundledTemplateKeys($path.'/.env.example');

        // Asserted before the diff: two empty reads diff to nothing, and
        // nothing is the answer a correct pair gives too.
        expect(count($bundled))->toBeGreaterThan(10)
            ->and(count($example))->toBeGreaterThan(10);

        foreach (array_keys($example) as $key) {
            if (! array_key_exists($key, $bundled) && ! array_key_exists($key, BUNDLED_TEMPLATE_OMITS)) {
                $unshipped[] = $root.': '.$key;
            }
        }
    }

    expect($unshipped)->toBe([], implode("\n", [
        'These keys are declared for local development and not for the bundle, so a',
        'shipped install resolves them from the framework default rather than from',
        'anything this product chose:',
        ...$unshipped,
        '',
        'Add the value the bundle should carry, or pin the key in',
        'BUNDLED_TEMPLATE_OMITS with why the bundle is right not to carry it.',
    ]));
});

it('holds no omission whose reason has stopped being true', function (): void {
    $stale = [];

    foreach (array_keys(BUNDLED_TEMPLATE_OMITS) as $key) {
        $carried = array_filter(
            BUNDLED_TEMPLATE_ROOTS,
            static fn (string $root): bool => array_key_exists($key, bundledTemplateKeys(bundledTemplateRoot($root).'/.env.example')),
        );

        if ($carried === []) {
            $stale[] = $key.' is pinned as deliberately unshipped and no development template declares it any more';
        }
    }

    expect($stale)->toBe([], implode("\n", [
        'An omission pinned for a key nobody declares widens the exemption silently:',
        ...$stale,
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
