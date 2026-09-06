<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// The app has two Composer roots — the repo root the desktop and the test
// suite run from, and mobile-app/, whose vendor/ is the one that ships inside
// the phone build. They share every line of Modules/, so a library pinned
// differently in the two files is code written against one version and shipped
// against another. brick/money sat at ^0.14 here and ^0.11 there: the phone
// fatalled with `Class "Brick\Money\ExchangeRateProvider\Configurable\
// ConfigurableProviderBuilder" not found` the first time anything asked it to
// convert a currency, and CI could not see it because the mobile root runs
// only the Mobile testsuite.

/**
 * @return array{0: array<string, string>, 1: array<string, string>}
 */
function composerRootRequires(): array
{
    $decode = static function (string $path): array {
        $raw = (string) file_get_contents($path);
        /** @var array{require?: array<string, string>} $json */
        $json = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return $json['require'] ?? [];
    };

    return [
        $decode(base_path('composer.json')),
        $decode(base_path('mobile-app/composer.json')),
    ];
}

it('pins every shared runtime dependency to the same constraint in both Composer roots', function (): void {
    [$root, $mobile] = composerRootRequires();

    $mismatched = [];
    $shared = 0;

    foreach (array_keys($root) as $package) {
        // A package only one root requires is not a shared line of code, so the
        // two constraints have nothing to agree about.
        if (! isset($mobile[$package])) {
            continue;
        }

        $shared++;

        if ($root[$package] !== $mobile[$package]) {
            $mismatched[$package] = sprintf('root %s vs mobile-app %s', $root[$package], $mobile[$package]);
        }
    }

    // The two roots share 43 requires today. A comparison that found none of
    // them agrees about nothing and reports a clean pair.
    expect($shared)->toBeGreaterThan(
        20,
        'No package is required by both roots, so this rule compared nothing at all.'
    );

    expect($mismatched)->toBe([], implode("\n  ", [
        'These libraries are pinned differently in the two Composer roots:',
        ...array_map(static fn (string $package): string => $package.': '.$mismatched[$package], array_keys($mismatched)),
        '',
        'Modules/ is one tree read from both roots, so a library at two versions is',
        'code written against one and shipped against the other. brick/money at ^0.14',
        'here and ^0.11 there fatalled the phone the first time anything converted a',
        'currency, and CI could not see it: the mobile root runs only the Mobile suite.',
    ]));
});

/** @return list<string> the bootstrap file of each Composer root */
function composerRootBootstraps(): array
{
    return ['bootstrap/app.php', 'mobile-app/bootstrap/app.php'];
}

// Balanced-paren scan rather than a regex: the block holds closures whose own
// bodies carry parentheses, and `.*?` stops at the first one of them. Comments
// come off first — the prose explaining a mapping names the very types the
// comparison below reads, so a sentence would count as a registration.
function composerRootExceptionBlock(string $relativePath): string
{
    $source = '';
    foreach (token_get_all((string) file_get_contents(base_path($relativePath))) as $token) {
        if (is_array($token)) {
            $source .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];

            continue;
        }

        $source .= $token;
    }

    $open = strpos($source, 'withExceptions(');

    expect($open)->not->toBeFalse("{$relativePath} configures no exception handler at all.");

    $depth = 0;
    $start = (int) $open + strlen('withExceptions');
    for ($i = $start; $i < strlen($source); $i++) {
        $depth += (int) ($source[$i] === '(') - (int) ($source[$i] === ')');
        if ($depth === 0) {
            return substr($source, $start, $i - $start + 1);
        }
    }

    throw new RuntimeException("Unbalanced withExceptions() block in {$relativePath}.");
}

/** @return array{methods: list<string>, types: list<string>} */
function composerRootExceptionShape(string $relativePath): array
{
    $block = composerRootExceptionBlock($relativePath);

    $methods = PatternScan::all('/\$exceptions->(\w+)\(/', $block);
    $types = PatternScan::all('/\b([A-Z]\w*(?:Exception|Error))\b/', $block);

    $unique = static function (array $found): array {
        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    };

    return ['methods' => $unique($methods[1]), 'types' => $unique($types[1])];
}

// The requires above are one half of what has to agree. The other is the
// exception handler: the mapping of refused Livewire writes was written on the
// desktop root alone once, and the QueryException reportable — the one thing
// keeping a query's bindings, which here ARE the financial data, out of the log
// — a second time. Nothing but this compares the two.
it('configures the same exception handler in both Composer roots', function (): void {
    [$rootPath, $mobilePath] = composerRootBootstraps();

    $root = composerRootExceptionShape($rootPath);
    $mobile = composerRootExceptionShape($mobilePath);

    // Read before the comparison: two empty shapes are equal, so a block reader
    // that stopped matching would report the roots in perfect agreement.
    expect(count($root['methods']))->toBeGreaterThan(
        1,
        $rootPath.' registers no $exceptions-> callback the reader could find, so the block reader has stopped matching.'
    );
    expect(count($root['types']))->toBeGreaterThan(
        1,
        $rootPath.' names no exception type the reader could find, so the block reader has stopped matching.'
    );

    expect($mobile['methods'])->toBe(
        $root['methods'],
        "The two roots register different \$exceptions-> callbacks. A handler present on one bundle and absent on the other is two answers to one fault.\n".
        "  {$rootPath}: ".implode(', ', $root['methods'])."\n".
        "  {$mobilePath}: ".implode(', ', $mobile['methods']),
    );

    expect($mobile['types'])->toBe(
        $root['types'],
        "The two roots' exception handlers name different exception types.\n".
        "  {$rootPath}: ".implode(', ', $root['types'])."\n".
        "  {$mobilePath}: ".implode(', ', $mobile['types']),
    );
});

// Not part of the handler comparison above because it lives in booting(), and
// those two blocks legitimately differ. The file is created empty here and the
// migrator writes every balance and account number into it afterwards, so the
// mode has to be narrowed before that and on both roots.
it('creates the SQLite file owner-only from both Composer roots', function (): void {
    foreach (composerRootBootstraps() as $path) {
        $source = (string) file_get_contents(base_path($path));

        expect(str_contains($source, 'EnsurePrivateDatabaseFile::class'))->toBeTrue(
            "{$path} brings the database file into existence without EnsurePrivateDatabaseFile, so nothing verifies that the umask did not leave the ledger readable.",
        );
    }
});
