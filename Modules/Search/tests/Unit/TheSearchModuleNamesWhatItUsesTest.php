<?php

declare(strict_types=1);

use Modules\Search\Internal\Services\PaletteSectionComposer;
use Modules\Search\Internal\Services\SearchDocumentBody;
use Modules\Search\Public\Contracts\SearchResultsProvider;
use Modules\Search\Public\Enums\SearchEntityKind;

// Two imports in this module were dead code that Pint's unused-import rule kept
// alive, because a prose // comment happened to spell their names. That makes a
// comment load-bearing for the import list, which is the comment policy exactly
// inverted: the rules below let a PHPDoc tag justify an import, and nothing else.

/**
 * @return list<string>
 */
function searchModuleProductionFiles(): array
{
    $files = [];
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules/Search'), RecursiveDirectoryIterator::SKIP_DOTS),
    ) as $file) {
        $path = $file->getPathname();
        if (! $file->isFile() || ! str_ends_with($path, '.php')) {
            continue;
        }
        if (str_contains($path, '/tests/') || str_contains($path, '/Database/') || str_contains($path, '/Resources/')) {
            continue;
        }
        $files[] = $path;
    }
    sort($files);

    return $files;
}

// A PHPDoc tag is machine input — @return, @param and @phpstan-import-type all
// need the short name in scope — so it counts as a use. A // or /* */ comment is
// for a human and cannot hold an import up on its own.
function searchModuleSourceWithoutProse(string $path): string
{
    $kept = '';
    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && $token[0] === T_COMMENT) {
            continue;
        }
        $kept .= is_array($token) ? $token[1] : $token;
    }

    return $kept;
}

/**
 * @return list<string>
 */
function searchModuleUnusedImports(string $path): array
{
    $source = searchModuleSourceWithoutProse($path);

    if (preg_match_all('/^use\s+([^;]+);$/m', $source, $matches, PREG_SET_ORDER) === 0) {
        return [];
    }

    $body = (string) preg_replace('/^use\s+[^;]+;$/m', '', $source);

    $unused = [];
    foreach ($matches as $match) {
        $imported = trim($match[1]);
        $separator = strrpos($imported, '\\');
        $alias = str_contains($imported, ' as ')
            ? trim(substr($imported, (int) strrpos($imported, ' as ') + 4))
            : ($separator === false ? $imported : substr($imported, $separator + 1));

        if (preg_match('/\b'.preg_quote($alias, '/').'\b/', $body) !== 1) {
            $unused[] = $imported;
        }
    }

    return $unused;
}

it('leans on no import that only a prose comment keeps alive', function (): void {
    $hits = [];
    foreach (searchModuleProductionFiles() as $path) {
        foreach (searchModuleUnusedImports($path) as $import) {
            $hits[] = str_replace(base_path().'/', '', $path).'  '.$import;
        }
    }

    expect($hits)->toBe([], implode("\n", [
        'These imports are used by nothing but a comment:',
        ...$hits,
        '',
        'Delete the import and the sentence that was holding it up. A PHPDoc tag is',
        'machine input and does count; a // or /* */ comment does not.',
    ]));
});

it('has retired the name SearchResultsProviderImpl', function (): void {
    $survivors = [];
    foreach ([...searchModuleProductionFiles(), base_path('.docs/features/search/architecture.md')] as $path) {
        if (str_contains((string) file_get_contents($path), 'SearchResultsProviderImpl')) {
            $survivors[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($survivors)->toBe([]);
    expect(app(SearchResultsProvider::class))
        ->toBeInstanceOf(PaletteSectionComposer::class);
});

it('writes the palette entity kinds through the enum, never as bare strings', function (): void {
    $sources = [
        base_path('Modules/Search/Internal/Services/EntityNameSearch.php'),
        base_path('Modules/DevMode/Resources/views/livewire/command-palette-modal.blade.php'),
    ];

    $hits = [];
    foreach ($sources as $path) {
        $source = (string) file_get_contents($path);
        foreach (SearchEntityKind::cases() as $kind) {
            foreach (["'".$kind->value."'", '"'.$kind->value.'"'] as $literal) {
                if (str_contains($source, $literal)) {
                    $hits[] = str_replace(base_path().'/', '', $path).'  '.$literal;
                }
            }
        }
    }

    expect($hits)->toBe([]);
});

it('measures the trigram width against one named constant', function (): void {
    expect(SearchDocumentBody::TRIGRAM_WIDTH)->toBe(3);

    $unnamed = [];
    foreach ([
        'Modules/Search/Internal/Services/FtsCandidateResolver.php',
        'Modules/Search/Internal/Services/DidYouMeanSuggester.php',
        'Modules/Search/Public/Services/SearchQuery.php',
    ] as $relative) {
        if (! str_contains((string) file_get_contents(base_path($relative)), 'SearchDocumentBody::TRIGRAM_WIDTH')) {
            $unnamed[] = $relative;
        }
    }

    expect($unnamed)->toBe([]);
});
