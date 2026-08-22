<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

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
 * Short class name to every first-party FQCN that answers to it. Two modules
 * may legitimately both define a `Handler`, so a prose mention is satisfied by
 * any of them — this rule asks whether the symbol exists, not which one.
 *
 * @return array<string, list<class-string>>
 */
function docsSymbolsFirstPartyClasses(): array
{
    $map = [];

    foreach (docsSymbolsPhpFiles() as $path) {
        $source = (string) file_get_contents($path);

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1) {
            continue;
        }
        if (preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $cls) !== 1) {
            continue;
        }

        /** @var class-string $fqcn */
        $fqcn = trim($ns[1]).'\\'.$cls[1];
        $map[$cls[1]][] = $fqcn;
    }

    return $map;
}

/**
 * @return list<string>
 */
function docsSymbolsPhpFiles(): array
{
    $files = [];

    foreach ([base_path('Modules'), base_path('app')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            $path = $file->getPathname();

            if ($file->isFile() && str_ends_with($path, '.php') && ! str_contains($path, '/Database/Migrations/')) {
                $files[] = $path;
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * Reflection rather than a grep for `function <name>`, because a member is just
 * as real when it arrives from a parent or a trait — asking the class itself is
 * the only way to tell an inherited method from an absent one.
 *
 * @param  list<class-string>  $candidates
 */
function docsSymbolsHasMember(array $candidates, string $member): bool
{
    foreach ($candidates as $fqcn) {
        // A class whose parent ships only under mobile-app/vendor cannot be
        // loaded from this root, and autoloading it throws rather than
        // answering false. Unverifiable here is not the same as absent, so it
        // is skipped rather than reported.
        try {
            if (! class_exists($fqcn) && ! interface_exists($fqcn) && ! trait_exists($fqcn) && ! enum_exists($fqcn)) {
                continue;
            }

            $reflection = new ReflectionClass($fqcn);
        } catch (Throwable) {
            return true;
        }

        if ($reflection->hasMethod($member) || $reflection->hasConstant($member) || $reflection->hasProperty($member)) {
            return true;
        }

        // An Eloquent model answers to its builder through __callStatic, so a
        // page naming updateOrCreate() or where() is describing something that
        // genuinely works; reflection alone would call it absent.
        if ($reflection->isSubclassOf(Model::class) && docsSymbolsBuilderAnswers($member)) {
            return true;
        }
    }

    return false;
}

function docsSymbolsBuilderAnswers(string $member): bool
{
    foreach ([EloquentBuilder::class, QueryBuilder::class] as $builder) {
        if (method_exists($builder, $member)) {
            return true;
        }
    }

    return false;
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
    $classes = docsSymbolsFirstPartyClasses();
    $pages = docsSymbolsPages();

    expect($classes)->not->toBe([]);
    expect($pages)->not->toBe([]);

    $hits = [];

    foreach ($pages as $page) {
        $prose = docsSymbolsProse((string) file_get_contents($page));
        $label = str_replace(base_path().'/', '', $page);

        foreach (preg_split('/\R/', $prose) ?: [] as $offset => $line) {
            foreach (docsSymbolsMentions($line) as [$class, $member]) {
                if (! isset($classes[$class]) || docsSymbolsHasMember($classes[$class], $member)) {
                    continue;
                }

                $hits[] = $label.':'.($offset + 1).'  '.$class.'::'.$member;
            }
        }
    }

    expect($hits)->toBe([], "A documentation page names a method, constant or property that its class does not have. M6 proves a LINK resolves; nothing proved that PROSE does, and a page describing code that was renamed reads exactly like a page describing code that ships — prose carries no version. Only first-party classes are checked, and only inline-code mentions outside fenced blocks; a framework facade or an illustrative snippet is not the subject. Offenders:\n  ".implode("\n  ", $hits));
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
    $classes = docsSymbolsFirstPartyClasses();

    expect($classes)->toHaveKey('User');
    expect(docsSymbolsHasMember($classes['User'], 'save'))->toBeTrue();
    expect(docsSymbolsHasMember($classes['User'], 'thisIsNotAMethodAnywhere'))->toBeFalse();
});
