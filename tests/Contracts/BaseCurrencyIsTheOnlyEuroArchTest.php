<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md
 */

// The two files allowed to spell the code out: the enum that declares the case,
// and the symbol table that maps it. Everywhere else names it through
// Currency::Eur or resolves it through BaseCurrency, so a reader who changes
// their base currency changes it everywhere rather than in the sites that
// happened to be written last.
const EURO_LITERAL_HOMES = [
    'Modules/Ledger/Public/Enums/Currency.php',
    'Modules/Ledger/Public/ValueObjects/Money.php',
    // A closed strip-list of ISO codes the amount parser removes before reading
    // a number, three of which the enum does not carry. Naming only the four it
    // does would read as a set with four members and three strays.
    'Modules/Ingestion/Internal/Adapters/Ics/IcsAmountParser.php',
];

/**
 * @return list<string>
 */
function euroLiteralSources(): array
{
    $files = [];

    // resources, routes, config and database are here beside Modules and app
    // because the rule says "never as a bare literal" and a bare EUR in a shell
    // view or a root seeder is the same frozen currency in front of the same
    // reader. A walk reading two roots while claiming the tree is what the
    // exemption sweep this file came out of exists to refuse.
    foreach (['Modules', 'app', 'resources', 'routes', 'config', 'database'] as $root) {
        $absolute = base_path($root);

        if (is_dir($absolute)) {
            $files = array_merge($files, euroLiteralWalk($absolute));
        }
    }

    sort($files);

    return $files;
}

/**
 * @return list<string>
 */
function euroLiteralWalk(string $directory): array
{
    $files = [];

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        // mobile-app/ reaches this same tree through symlinks, and following one
        // reports every shared file a second time under a second spelling.
        if (is_link($path)) {
            continue;
        }

        if (is_dir($path)) {
            // Fixtures name currencies because they assert on them, translations
            // are data, and a migration's default is the column's own history —
            // spelled twice because a module writes Migrations/ and the shared
            // root writes migrations/.
            if (in_array($entry, ['tests', 'lang', 'Migrations', 'migrations'], true)) {
                continue;
            }

            $files = array_merge($files, euroLiteralWalk($path));

            continue;
        }

        if (str_ends_with($path, '.php')) {
            $files[] = $path;
        }
    }

    return $files;
}

/**
 * Read as tokens rather than matched as text: eleven of these files explain the
 * fallback in prose directly above the code, and a scan reading that prose would
 * report a literal nobody wrote.
 *
 * @return list<int> the 1-indexed lines carrying a bare 'EUR' string literal
 */
function euroLiteralLines(string $source, bool $isBlade): array
{
    return $isBlade ? euroLiteralBladeLines($source) : euroLiteralPhpLines($source);
}

/**
 * @return list<int>
 */
function euroLiteralPhpLines(string $source): array
{
    $lines = [];

    foreach (@token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && trim($token[1], '"\'') === 'EUR') {
            $lines[] = $token[2];
        }
    }

    return $lines;
}

/**
 * Matched as text, unlike the PHP branch above, because a Blade file is not PHP
 * until Blade compiles it: `token_get_all` enters PHP mode only at a literal
 * `<?php`, so a literal inside `@php`, `{{ }}` or an `@if` condition tokenises as
 * inline HTML and is never seen. Two converted sites were invisible to the token
 * reader for exactly that reason. Blade's own comment form is blanked first, and
 * blanked newline-for-newline so a reported line still points at the line to edit.
 *
 * @return list<int>
 */
function euroLiteralBladeLines(string $source): array
{
    $source = PatternScan::replaceCallback(
        '/\{\{--.*?--\}\}/s',
        static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
        $source,
    );

    $lines = [];

    foreach (explode("\n", $source) as $offset => $line) {
        if (preg_match('/([\'"])EUR\1/', $line) === 1) {
            $lines[] = $offset + 1;
        }
    }

    return $lines;
}

it('resolves the euro through the enum or the service, never as a bare literal', function (): void {
    $offenders = [];
    $files = euroLiteralSources();

    // Two and a half thousand files stand behind the empty list below. A run
    // that read a handful found no literal because it stopped.
    expect(count($files))->toBeGreaterThan(
        1_000,
        'The walk read almost nothing, so the empty offender list below is a tree nobody opened.',
    );

    foreach ($files as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        if (in_array($relative, EURO_LITERAL_HOMES, true)) {
            continue;
        }

        $source = file_get_contents($path);

        if ($source === false) {
            continue;
        }

        foreach (euroLiteralLines($source, str_ends_with($path, '.blade.php')) as $line) {
            $offenders[] = $relative.':'.$line;
        }
    }

    expect($offenders)->toBe([], sprintf(
        "%d bare 'EUR' literal(s). Inject BaseCurrency and call code(), use BaseCurrency::value() in Blade, ".
        "or name a fixed external fact with Currency::Eur->value:\n  %s",
        count($offenders),
        implode("\n  ", $offenders),
    ));
});

// The rule reads string literals, so it has to be blind to the same three
// characters written as prose, and it has to see through a Blade comment to the
// code below it.
it('reads literals and not the prose around them', function (): void {
    $prose = "<?php\n// The EUR fallback is documented above.\n\$a = 'USD';\n";
    $blade = "{{-- 'EUR' in a comment --}}\n<?php \$b = 'EUR'; ?>\n";

    expect(euroLiteralLines($prose, false))->toBe([])
        ->and(euroLiteralLines("<?php\n\$c = 'EUR';\n", false))->toBe([2])
        ->and(euroLiteralLines($blade, true))->toBe([2]);
});

// The forms a Blade file actually writes a literal in. None of these reaches PHP
// mode, so the token reader saw an empty file and reported nothing — which is
// how two converted sites were never on the list in the first place.
it('sees a literal in the Blade forms that never enter PHP mode', function (): void {
    $php = "@php\n    \$fmt = 'EUR';\n@endphp\n";
    $echo = "<span>{{ Money::ofMinor(\$m, 'EUR') }}</span>\n";
    $directive = "@if (\$currency === 'EUR')\n    <b>base</b>\n@endif\n";

    expect(euroLiteralBladeLines($php))->toBe([2])
        ->and(euroLiteralBladeLines($echo))->toBe([1])
        ->and(euroLiteralBladeLines($directive))->toBe([1])
        ->and(euroLiteralPhpLines($php))->toBe([]);
});

// A home that no longer writes the literal is excused for something it stopped
// doing, and the exemption then stands ready to excuse whatever is written
// there next. Each is re-read against the same detector the walk drives.
it('keeps no euro home that has stopped spelling the euro out', function (): void {
    $dead = [];

    foreach (EURO_LITERAL_HOMES as $relative) {
        $path = base_path($relative);

        if (! is_file($path)) {
            $dead[] = $relative.' is no longer in the tree';

            continue;
        }

        $source = (string) file_get_contents($path);

        if (euroLiteralLines($source, str_ends_with($relative, '.blade.php')) === []) {
            $dead[] = $relative.' spells no bare EUR any more';
        }
    }

    expect($dead)->toBe([], implode("\n  ", [
        'These files are allowed to spell the euro out and no longer do. The exemption excuses nothing',
        'while reading as considered, and it covers whatever is written into the file next:',
        ...$dead,
    ]));
});
