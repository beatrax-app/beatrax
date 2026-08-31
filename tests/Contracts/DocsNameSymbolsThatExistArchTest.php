<?php

declare(strict_types=1);

use Tests\Contracts\Support\FirstPartySymbols;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md
 */

// Fenced blocks are illustration, not reference: a page teaching a syntax or
// quoting a shape deliberately writes symbols that need not exist. Blanked
// rather than dropped so a reported line number still points at the real line.
function docsSymbolsProse(string $source): string
{
    $blanked = (string) preg_replace_callback(
        '/```.*?```/s',
        static fn (array $m): string => (string) preg_replace('/[^\r\n]/', ' ', $m[0]),
        $source,
    );

    return $blanked;
}

/**
 * @return list<string>
 */
function docsSymbolsPages(): array
{
    $pages = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('.docs'), RecursiveDirectoryIterator::SKIP_DOTS),
    ) as $file) {
        if ($file->isFile() && str_ends_with($file->getPathname(), '.md')) {
            $pages[] = $file->getPathname();
        }
    }

    sort($pages);

    return $pages;
}

it('names no first-party symbol that does not exist', function (): void {
    $classes = FirstPartySymbols::classes();
    $pages = docsSymbolsPages();

    expect($classes)->not->toBe([]);
    expect($pages)->not->toBe([]);

    $hits = [];

    foreach ($pages as $page) {
        $prose = docsSymbolsProse((string) file_get_contents($page));
        $label = str_replace(base_path().'/', '', $page);

        foreach (preg_split('/\R/', $prose) ?: [] as $offset => $line) {
            foreach (docsSymbolsMentions($line) as [$class, $member]) {
                if (! isset($classes[$class]) || FirstPartySymbols::hasMember($classes[$class], $member)) {
                    continue;
                }

                $hits[] = $label.':'.($offset + 1).'  '.$class.'::'.$member;
            }
        }
    }

    expect($hits)->toBe([], "A documentation page names a method, constant or property that its class does not have. M6 proves a LINK resolves; nothing proved that PROSE does, and a page describing code that was renamed reads exactly like a page describing code that ships — prose carries no version. Only first-party classes are checked, and only inline-code mentions outside fenced blocks; a framework facade or an illustrative snippet is not the subject. Offenders:\n  ".implode("\n  ", $hits));
});

// The three names below resolve to no class in either Composer root and are
// written on purpose: two Android platform types the shell's own Kotlin calls,
// and one placeholder in a sentence about what a regex matches.
const DOCS_SYMBOLS_NAMING_NO_CLASS_BY_DESIGN = [
    'AndroidStreamReaderURLLoader',
    'SomeClass',
    'WebResourceResponse',
];

/**
 * Both roots' optimised classmaps, plus the module directories, because
 * `Import::NormalizeStage` names a namespace segment rather than a class and
 * is the densest mention shape in .docs.
 *
 * @return array<string, true>
 */
function docsSymbolsResolvableNames(): array
{
    $names = [];

    foreach (array_keys(FirstPartySymbols::classes()) as $short) {
        $names[$short] = true;
    }

    foreach (['vendor/composer/autoload_classmap.php', 'mobile-app/vendor/composer/autoload_classmap.php'] as $classmap) {
        $path = base_path($classmap);
        if (! is_file($path)) {
            continue;
        }

        /** @var array<string, string> $entries */
        $entries = require $path;
        foreach (array_keys($entries) as $fqcn) {
            $names[substr((string) strrchr('\\'.$fqcn, '\\'), 1)] = true;
        }
    }

    foreach (scandir(base_path('Modules')) ?: [] as $entry) {
        if (! str_starts_with($entry, '.') && is_dir(base_path('Modules/'.$entry))) {
            $names[$entry] = true;
        }
    }

    foreach (DOCS_SYMBOLS_NAMING_NO_CLASS_BY_DESIGN as $allowed) {
        $names[$allowed] = true;
    }

    return $names;
}

// The rule above skips a mention whose CLASS it does not recognise, so its one
// blind spot is a class that was never written at all — the shape a security
// page takes when it credits a defence to a name nothing answers to. Read
// through the classmaps rather than reflection: a third-party class this root
// never loads is still a class that exists, and calling it absent would report
// every framework mention on every page.
it('names no class that exists in neither Composer root', function (): void {
    $resolvable = docsSymbolsResolvableNames();
    $pages = docsSymbolsPages();

    expect($resolvable)->not->toBe([]);
    expect($pages)->not->toBe([]);

    $hits = [];

    foreach ($pages as $page) {
        $prose = docsSymbolsProse((string) file_get_contents($page));
        $label = str_replace(base_path().'/', '', $page);

        foreach (preg_split('/\R/', $prose) ?: [] as $offset => $line) {
            foreach (docsSymbolsMentions($line) as [$class, $member]) {
                if (isset($resolvable[$class]) || class_exists($class) || interface_exists($class) || enum_exists($class)) {
                    continue;
                }

                $hits[] = $label.':'.($offset + 1).'  '.$class.'::'.$member;
            }
        }
    }

    expect($hits)->toBe([], "A documentation page credits behaviour to a class that exists nowhere — not in Modules/, not in app/, and not in either root's vendor/. A name the first rule cannot recognise is a name it skips, so an invented class reads exactly like a correct one. Either the class is real and the page should spell it the way the code does, or the page is describing something that was never written. Offenders:\n  ".implode("\n  ", $hits));
});

/**
 * Read the whole inline-code span, then look inside it. Anchoring on a closing
 * backtick made every mention carrying arguments invisible — `Foo::bar($user)`
 * and a full signature both passed silently, and `code.md` writes signatures by
 * convention, so the densest carrier of stale names was the least visible.
 *
 * @return list<array{0: string, 1: string}>
 */
function docsSymbolsMentions(string $line): array
{
    if (preg_match_all('/`([^`\n]+)`/', $line, $spans) === 0) {
        return [];
    }

    $found = [];

    foreach ($spans[1] as $span) {
        if (preg_match_all('/\b([A-Z]\w+)::(\w+)/', $span, $matches, PREG_SET_ORDER) === 0) {
            continue;
        }

        foreach ($matches as $match) {
            // ::class is a language construct rather than a member, and no
            // class declares one, so every mention of it would be an offender.
            // A trailing underscore is where a wildcard was cut off — a page
            // naming ONE_OFF_ENVELOPE_*_MULTIPLIER means both constants, and
            // neither half of it is a member anything declares.
            if ($match[2] !== 'class' && ! str_ends_with($match[2], '_')) {
                $found[] = [$match[1], $match[2]];
            }
        }
    }

    return $found;
}

it('reads a mention only where it is a reference, not an illustration', function (): void {
    $fenced = "before\n```php\n`Ghost::vanished()`\n```\nafter";
    expect(docsSymbolsMentions(docsSymbolsProse($fenced)))->toBe([]);

    expect(docsSymbolsMentions('the `Foo::bar()` seam'))->toBe([['Foo', 'bar']]);
    expect(docsSymbolsMentions('holds `Foo::BAR` and `Baz::qux`'))->toBe([['Foo', 'BAR'], ['Baz', 'qux']]);
    expect(docsSymbolsMentions('a bare `Foo` name'))->toBe([]);
});

it('counts an inherited member as present', function (): void {
    $classes = FirstPartySymbols::classes();

    expect($classes)->toHaveKey('User');
    expect(FirstPartySymbols::hasMember($classes['User'], 'save'))->toBeTrue();
    expect(FirstPartySymbols::hasMember($classes['User'], 'thisIsNotAMethodAnywhere'))->toBeFalse();
});
