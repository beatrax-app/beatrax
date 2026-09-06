<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\GitleaksRuleset;
use Tests\Contracts\Support\SecretShapedValues;

// The secret gate checks out with `fetch-depth: 0` and runs `gitleaks git .`
// with no `--log-opts`, so it walks every ref in the repository rather than the
// pull request under review. A fixture shaped like a key therefore fails
// `security / gitleaks` on everybody ELSE's open pull request, for content
// their diff does not contain — three times here, four to nine PRs each time,
// and never on the branch that wrote it. This is the rule that stops the
// fourth, on the branch that writes it.

/**
 * Every distinct directory in this repository that holds tests. mobile-app/tests
 * is a symlink onto tests/, so walking it would read every file twice and report
 * every fixture twice.
 *
 * @return list<string>
 */
function secretGateTestRoots(): array
{
    return [base_path('tests'), ...glob(base_path('Modules/*/tests')) ?: []];
}

/**
 * The roots an allowlisted path could name. A path in .gitleaks.toml naming a
 * root that is not here reads as stale below and fails loudly rather than
 * quietly — which is the right way round, but it is why a new root has to be
 * added here in the same commit as the entry that names it.
 *
 * @return list<string>
 */
function secretGateRepositoryRoots(): array
{
    return ['app', 'bootstrap', 'config', 'database', 'lang', 'Modules', 'resources', 'routes', 'scripts', 'tests'];
}

/** @return array<string, string> absolute path => path relative to the repository root */
function secretGateFilesUnder(string ...$roots): array
{
    $files = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile()) {
                $files[$file->getPathname()] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    ksort($files);

    return $files;
}

/** @return list<string> the remedy, spelled out where a contributor trips over it */
function secretGateRemedy(): array
{
    return [
        'The fix is a runtime-assembled literal and an assertion on the value it produces.',
        'Never an entry in .gitleaks.toml, never an inline suppression: an exclusion turns',
        'the gate off for the whole file, and the next secret-shaped value written there is',
        'the one nobody is told about. Three spellings already carry this repository:',
        '',
        "    \$key = 'sk_'.'test_'.'51H8xQ2Kj3nRtYuIoP0aSdFgHjKlZ';   // splits the prefix a vendor rule keys on",
        "    \$hex = str_repeat('a1b2c3d4', 8);                       // builds the digits rather than spelling them",
        "    \$phrase = implode('-', ['correct', 'horse', 'battery']); // assembles the passphrase from words",
        '',
        'Then assert on the value, not on the literal: `expect($scrubbed)->not->toContain($key)`',
        'still proves what the spelled-out fixture proved, and reads the same at a failure.',
    ];
}

it('leaves no fixture under a test root that the secret gate would match', function (): void {
    $files = secretGateFilesUnder(...secretGateTestRoots());

    expect(count($files))->toBeGreaterThan(1_000, 'the walk read almost no test files — it is broken, not the tree');

    $offenders = [];

    foreach ($files as $path => $relative) {
        foreach (SecretShapedValues::in($relative, (string) file_get_contents($path)) as $finding) {
            $offenders[] = $relative.':'.$finding['line'].'  '.$finding['rule'].'  '.substr(str_replace("\n", '\n', $finding['secret']), 0, 60);
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These fixtures match a rule in the ruleset `security / gitleaks` applies, so each one',
        'fails that check on every open pull request in the repository — not just on this one:',
        '',
        ...array_slice($offenders, 0, 20),
        '',
        ...secretGateRemedy(),
    ]));
});

// Everything above rests on one claim: that the ruleset vendored beside this
// file is the ruleset the gate runs. `.gitleaks.toml` extending the defaults
// and declaring none of its own is what makes that true, so it is asserted
// rather than assumed.
it('reads the ruleset the gate applies, and every pattern in it reaches PCRE', function (): void {
    expect(GitleaksRuleset::repositoryConfigExtendsTheDefaults())->toBeTrue(
        '.gitleaks.toml no longer extends the default ruleset, or now declares rules of its own. '.
        'The vendored copy at '.GitleaksRuleset::VENDORED.' stopped describing what CI runs, and this '.
        'guard is answering about a different ruleset than the gate.',
    );

    $rules = GitleaksRuleset::rules();

    expect(count($rules))->toBeGreaterThan(200, 'the ruleset parsed to almost nothing — the reader is broken, not upstream');

    $unreadable = [];

    foreach ($rules as $rule) {
        foreach ([$rule['regex'], $rule['path']] as $pattern) {
            if (is_string($pattern) && @preg_match(SecretShapedValues::pattern($pattern), '') === false) {
                $unreadable[] = $rule['id'];
            }
        }
    }

    expect($unreadable)->toBe([], implode("\n", [
        'These rules carry a pattern PCRE will not compile, so they scan nothing and this guard is',
        'silently blind to whatever they match. Go patterns are RE2 and mostly portable; one that is',
        'not has to be named here rather than left to fail open:',
        '',
        ...$unreadable,
    ]));
});

// A guard that cannot go red is a guard that says nothing. Every probe below is
// assembled from fragments for the same reason the fixtures it polices are: a
// dataset spelling a matching value out would itself fail the gate it describes,
// on everybody else's pull request.
it('finds each shape the gate matches and leaves the assembled remedy alone', function (array $fragments, ?string $rule): void {
    $found = SecretShapedValues::in('tests/Fixtures/gitleaks/ProbeTest.php', "<?php\n".implode('', $fragments)."\n");

    expect(array_column($found, 'rule'))->toBe(
        $rule === null ? [] : [$rule],
        $rule === null
            ? 'This shape is the remedy the failure message prescribes, and the reader reported it as a secret — which would send a contributor round in a circle.'
            : 'This shape fails `security / gitleaks` on every open pull request in the repository, and the reader did not see it.',
    );
})->with([
    'a vendor-prefixed key written out' => [["\$key = '", 'sk', "_live_51H8xQ2Kj3nRtYuIoP0aSdFgHjKlZ';"], 'stripe-access-token'],
    'the same key with its prefix split' => [["\$key = '", 'sk', "_'.'live_'.'51H8xQ2Kj3nRtYuIoP0aSdFgHjKlZ';"], null],
    'a high-entropy value beside a name that says key' => [["\$config = ['api_key' => '", 'a1B2c3D4e5F6g7H8i9J0kLmN', "'];"], 'generic-api-key'],
    'the same value built rather than spelled' => [["\$config = ['api_key' => str_repeat('", 'a1b2c3d4', "', 8)];"], null],
    'a value too flat to be a key' => [["\$config = ['api_key' => '", 'aaaaaaaaaaaaaaaaaaaaaaa', "'];"], null],
    'a value the ruleset knows is a placeholder' => [["\$config = ['api_key' => '", 'this-is-an-example-placeholder', "'];"], null],
    'a private key written out' => [
        ['-----BEGIN RSA PRIVATE ', "KEY-----\nMIIEowIBAAKCAQEAuzWHNM5f+amCjQztc5QTfJfzCC5J4nuW+L/aOxZ4f8J3Frew\n-----END RSA PRIVATE KEY-----"],
        'private-key',
    ],
    'a private key whose header is assembled' => [["\$pem = '-----BEGIN '.'RSA PRIVATE ", "KEY-----'.\$body.'-----END '.'RSA PRIVATE KEY-----';"], null],
    'a passphrase assembled from words' => [["return implode('-', ['correct', 'horse', ", "'battery', 'staple', 'in', 'the', 'hallway']);"], null],
]);

// The paths .gitleaks.toml exempts are the only files in this repository
// allowed to carry a matching value, and each was granted because deleting the
// value would delete the coverage. An exemption whose file has since been
// rewritten is an exclusion nobody is auditing any more.
it('keeps no exemption in .gitleaks.toml that has outlived what earned it', function (): void {
    $files = secretGateFilesUnder(...array_map(base_path(...), secretGateRepositoryRoots()));

    expect(count($files))->toBeGreaterThan(1_000, 'the walk read almost no files — it is broken, not the tree');

    $stale = [];

    foreach (GitleaksRuleset::repositoryAllowlists() as $allowlist) {
        /** @var list<string> $paths */
        $paths = $allowlist['paths'];

        foreach ($paths as $pattern) {
            $earning = 0;
            $matched = 0;

            foreach ($files as $path => $relative) {
                if (! PatternScan::matches(SecretShapedValues::pattern($pattern), $relative)) {
                    continue;
                }

                $matched++;
                $earning += SecretShapedValues::despiteRepositoryExemptions($relative, (string) file_get_contents($path)) === [] ? 0 : 1;
            }

            if ($earning === 0) {
                $stale[] = $pattern.($matched === 0 ? '  (names no file in the tree)' : '  (names '.$matched.' files, none of which the gate would match)');
            }
        }
    }

    expect($stale)->toBe([], implode("\n", [
        'These entries in .gitleaks.toml exempt something that no longer needs exempting. An',
        'allowlisted path turns the gate off for a whole file, so one left behind after the value',
        'it covered was rewritten is a hole nobody meant to leave open. Delete the entry:',
        '',
        ...$stale,
    ]));
});

// The inline form of the same hole, and the one that leaves no diff in a config
// file for a reviewer to disagree with. The needle is assembled so this rule
// does not report itself.
it('leaves no inline suppression of the secret gate under a test root', function (): void {
    $needle = 'gitleaks'.':allow';
    $files = secretGateFilesUnder(...secretGateTestRoots());
    $offenders = [];

    foreach ($files as $path => $relative) {
        if (str_contains((string) file_get_contents($path), $needle)) {
            $offenders[] = $relative;
        }
    }

    expect(is_file(base_path('.gitleaksignore')))->toBeFalse(implode("\n", [
        'A .gitleaksignore pins a finding by fingerprint, so the value stays in the tree and the',
        'entry has to be rewritten every time the line it sits on moves.',
        '',
        ...secretGateRemedy(),
    ]));

    expect($offenders)->toBe([], implode("\n", [
        'These files suppress the secret gate inline rather than fixing the value it matched:',
        '',
        ...$offenders,
        '',
        ...secretGateRemedy(),
    ]));
});
