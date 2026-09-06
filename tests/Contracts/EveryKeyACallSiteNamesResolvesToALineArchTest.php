<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Facades\Blade;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\PatternScan;

// TranslationParityArchTest asks whether the locales agree, and
// EveryTranslatedLineReachesAReaderArchTest whether anything renders a declared
// line. Neither can see a key no file declares at all: absent from all
// twenty-six it is in parity by construction, and Lang::get renders it as itself.
// @link ../../.docs/conventions/a-call-site-names-a-key-that-resolves.md

/** the one root both composer roots agree on — mobile-app/Modules is a symlink onto this tree */
function callSiteKeyRepoRoot(): string
{
    return dirname((string) realpath(base_path('Modules')));
}

/** @return list<string> the files that can hand a key to the translator */
function callSiteKeySourceFiles(): array
{
    $root = callSiteKeyRepoRoot();

    $files = [];
    foreach (['Modules', 'app', 'resources', 'routes', 'config', 'database'] as $dir) {
        if (! is_dir($root.'/'.$dir)) {
            continue;
        }
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($walk as $file) {
            $path = $file->getPathname();
            if (! str_ends_with($path, '.php')) {
                continue;
            }
            // A lang file declares a key rather than naming one, and a test may
            // name a key on purpose to assert what a missing one does.
            if (str_contains($path, '/Resources/lang/') || str_contains($path, '/tests/')) {
                continue;
            }
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

/**
 * The source with every comment dropped, so a key spelled in documentation is
 * not read as a call — `Lang::get('ns::group.key')` is how Lang's own header
 * explains itself. Blade goes through its own compiler first: a `{{-- --}}`
 * block is inline HTML to the PHP tokeniser, and the compiler is the one thing
 * that already knows where one ends.
 */
function callSiteKeyExecutableSource(string $source, bool $isBlade): string
{
    if ($isBlade) {
        $source = Blade::compileString($source);
    }

    $executable = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            continue;
        }
        $executable .= is_array($token) ? $token[1] : $token;
    }

    return $executable;
}

// Only a translation call. `view('onboarding::livewire.setup-wizard')` has the
// shape of a namespaced key and is a template, so the guard reads the callee
// rather than the string.
const CALL_SITE_KEY_TRANSLATION_CALLS = 'Lang::get|Lang::choice|Lang::group|trans_choice|trans|__';

// A scan that goes blind reports a clean tree, which is the one failure a guard
// must not have. The floor sits well under the 3,420 keys the product spells
// out today, and well over anything a pattern that stopped matching would leave.
const CALL_SITE_KEY_SCAN_FLOOR = 1000;

/**
 * @return list<string>
 *
 * @throws RuntimeException when the engine gives up rather than answering
 */
function callSiteKeyMatches(string $pattern, string $source): array
{
    /** @var list<string> $found */
    $found = PatternScan::all($pattern, $source)[1];

    return $found;
}

/**
 * The keys one file hands the translator: the ones it spells out, and the
 * prefixes it builds a key on without spelling any leaf.
 *
 * @return array{exact: list<string>, prefixes: list<string>}
 */
function callSiteKeyReferencesIn(string $executable): array
{
    $call = '(?<![\w$>\\\\])(?:'.CALL_SITE_KEY_TRANSLATION_CALLS.')\s*\(\s*';

    // An exact key is one whose quote closes and whose argument list then moves
    // on. Concatenation and interpolation are read off the punctuation that
    // follows or opens the literal, never off the key's own last character: a
    // prefix may end in a letter, and the trailing "_" is not what makes
    // 'core::settings.appearance.theme_' one.
    $exact = array_merge(
        callSiteKeyMatches('~'.$call.'\'((?:[^\'\\\\]|\\\\.)*)\'\s*[,)]~', $executable),
        callSiteKeyMatches('~'.$call.'"([^"${\\\\]*)"\s*[,)]~', $executable),
    );

    $prefixes = array_merge(
        callSiteKeyMatches('~'.$call.'\'((?:[^\'\\\\]|\\\\.)*)\'\s*\.~', $executable),
        callSiteKeyMatches('~'.$call.'"([^"${]*)[${]~', $executable),
    );

    return ['exact' => $exact, 'prefixes' => $prefixes];
}

/**
 * @param  array<array-key, mixed>  $lines
 * @return list<string>
 */
function callSiteKeyPaths(array $lines, string $prefix = ''): array
{
    $paths = [];
    foreach ($lines as $name => $line) {
        $path = $prefix === '' ? (string) $name : $prefix.'.'.$name;
        $paths[] = $path;
        if (is_array($line)) {
            $paths = array_merge($paths, callSiteKeyPaths($line, $path));
        }
    }

    return $paths;
}

/**
 * A prefix names a group and a path under it, and the group is the unit the
 * translator answers with — which is the only way to see leaves no literal
 * spells.
 *
 * @return array{0: string, 1: string} the group key, and the path a prefix reaches into
 */
function callSiteKeyGroupAndRemainder(string $prefix): array
{
    $namespace = '';
    $tail = $prefix;

    $separator = strpos($prefix, '::');
    if ($separator !== false) {
        $namespace = substr($prefix, 0, $separator + 2);
        $tail = substr($prefix, $separator + 2);
    }

    $dot = strpos($tail, '.');

    return $dot === false
        ? [$namespace.$tail, '']
        : [$namespace.substr($tail, 0, $dot), substr($tail, $dot + 1)];
}

/** @return list<string> every key path a group holds, or [] when nothing answers to it */
function callSiteKeyGroupPaths(string $group): array
{
    $lines = app(Translator::class)->get($group, [], Locale::DEFAULT, false);

    return is_array($lines) ? callSiteKeyPaths($lines) : [];
}

/** where the raw source spells the key, since the scan reads a compiled copy of it */
function callSiteKeyWhere(string $path, string $key): string
{
    $rel = str_replace(callSiteKeyRepoRoot().'/', '', $path);
    foreach (file($path) ?: [] as $index => $line) {
        if (str_contains($line, $key)) {
            return $rel.':'.($index + 1);
        }
    }

    return $rel;
}

it('has a line behind every key a translation call spells out', function (): void {
    $translator = app(Translator::class);

    $files = callSiteKeySourceFiles();

    // The floor sits far under the 2,700 files this walk opens.
    expect(count($files))->toBeGreaterThan(
        800,
        'The call-site walk opened almost nothing, so no key was read at all.'
    );

    $counted = ['blade' => 0, 'php' => 0];
    $unresolved = [];
    foreach ($files as $path) {
        $isBlade = str_ends_with($path, '.blade.php');
        $executable = callSiteKeyExecutableSource((string) file_get_contents($path), $isBlade);

        foreach (array_unique(callSiteKeyReferencesIn($executable)['exact']) as $key) {
            $counted[$isBlade ? 'blade' : 'php']++;

            $line = $translator->get($key, [], Locale::DEFAULT, false);
            if ($line === $key) {
                $unresolved[] = $key.' — '.callSiteKeyWhere($path, $key).' — no lang file declares it';
            } elseif (! is_string($line)) {
                $unresolved[] = $key.' — '.callSiteKeyWhere($path, $key).' — names a group, not a line';
            }
        }
    }

    // Both halves of the scan, because Blade reaches the tokeniser only after
    // its own compiler and a break there would empty that side alone.
    expect($counted['blade'])->toBeGreaterThan(0, 'No Blade template spelled a key, so the compiler half of the scan read nothing.')
        ->and($counted['php'])->toBeGreaterThan(0, 'No PHP file spelled a key, so the tokeniser half of the scan read nothing.')
        ->and($counted['blade'] + $counted['php'])->toBeGreaterThan(
            CALL_SITE_KEY_SCAN_FLOOR,
            'Fewer than '.CALL_SITE_KEY_SCAN_FLOOR.' keys were read across both halves, so the pattern has stopped matching.'
        );

    $unresolved = array_values(array_unique($unresolved));
    sort($unresolved);

    expect($unresolved)->toBe([], implode("\n", [
        'These keys are handed to the translator and nothing answers to them:',
        ...$unresolved,
        '',
        'Lang::get returns the key itself when no file declares it, so each of',
        'these renders "ns::group.key" to the reader in all 26 languages. Parity',
        'cannot see it — a key missing from every locale is in parity — so add',
        'the line to Modules/<X>/Resources/lang/en/ and to all 26 counterparts.',
    ]));
});

it('reaches a line through every prefix a translation call builds a key on', function (): void {
    $files = callSiteKeySourceFiles();

    expect(count($files))->toBeGreaterThan(
        800,
        'The call-site walk opened almost nothing, so no prefix was read at all.'
    );

    $unreachable = [];
    $prefixes = 0;

    foreach ($files as $path) {
        $executable = callSiteKeyExecutableSource(
            (string) file_get_contents($path),
            str_ends_with($path, '.blade.php')
        );

        foreach (array_unique(callSiteKeyReferencesIn($executable)['prefixes']) as $prefix) {
            $prefixes++;
            [$group, $remainder] = callSiteKeyGroupAndRemainder($prefix);

            $reaches = false;
            foreach (callSiteKeyGroupPaths($group) as $candidate) {
                if (str_starts_with($candidate, $remainder)) {
                    $reaches = true;

                    break;
                }
            }

            if (! $reaches) {
                $unreachable[] = $prefix.' — '.callSiteKeyWhere($path, $prefix);
            }
        }
    }

    $unreachable = array_values(array_unique($unreachable));
    sort($unreachable);

    // A prefix is the rarer shape and the one no literal names, so the floor
    // is low on purpose — but a scan that found none of them is a scan that
    // stopped, not a tree that spells every key out.
    expect($prefixes)->toBeGreaterThan(
        5,
        'No call site builds a key on a prefix at all, so this rule checked nothing.'
    );

    expect($unreachable)->toBe([], implode("\n", [
        'These call sites build a key on a prefix no line sits under:',
        ...$unreachable,
        '',
        'The leaf is chosen at runtime, so no literal names it and the rule',
        'above cannot check it. What can be checked is that the subtree exists',
        'at all — an empty one means every arm renders its own key.',
    ]));
});

// The shapes that look like a broken key and are not. A guard that reports any
// of them is a guard the next reader learns to skip, so each is proven here
// rather than assumed.
it('reads the call rather than the prose and the view names beside it', function (): void {
    $php = <<<'SOURCE'
        <?php
        /** Lang::get('core::nowhere.in_a_docblock') is how this class is explained. */
        final class Example
        {
            public function render(string $value, string $key): string
            {
                // Lang::get('core::nowhere.in_a_line_comment') explains the next line.
                view('onboarding::livewire.setup-wizard');

                return Lang::get('core::settings.save')
                    .Lang::get('core::settings.appearance.theme_'.$value)
                    .Lang::get("reports::index.summary.metric.{$key}");
            }
        }
        SOURCE;

    $found = callSiteKeyReferencesIn(callSiteKeyExecutableSource($php, isBlade: false));

    expect($found['exact'])->toBe(['core::settings.save'])
        ->and($found['prefixes'])->toBe([
            'core::settings.appearance.theme_',
            'reports::index.summary.metric.',
        ]);

    $blade = <<<'SOURCE'
        {{-- Lang::get('core::nowhere.in_a_blade_comment') draws the button below. --}}
        <button>{{ Lang::get('core::settings.save') }}</button>
        SOURCE;

    $fromBlade = callSiteKeyReferencesIn(callSiteKeyExecutableSource($blade, isBlade: true));

    expect($fromBlade['exact'])->toBe(['core::settings.save'])
        ->and($fromBlade['prefixes'])->toBe([]);
});
