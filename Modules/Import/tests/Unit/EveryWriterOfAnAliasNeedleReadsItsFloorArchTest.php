<?php

declare(strict_types=1);

use Modules\Import\Public\Services\AliasMatchPreviewQuery;

// merchant_aliases.generalized_pattern is matched by whole-token containment
// against every description the reader owns, so a needle of two characters
// renames a ledger. The floor stood on two of the four writers: a YAML import
// wrote whatever the generalizer produced ("AH 1234" is "ah"), and a bulk merge
// wrote the longest common prefix of the selected rows, which for two unrelated
// aliases is a character or two.

const ALIAS_NEEDLE_SEAM = 'MerchantAliasPattern';

const ALIAS_NEEDLE_COLUMN = "'generalized_pattern' =>";

/**
 * @return list<string>
 */
function aliasNeedleWriterFiles(): array
{
    $files = [];

    foreach (aliasNeedleWalk(base_path('Modules/Import')) as $path) {
        if (str_contains((string) file_get_contents($path), ALIAS_NEEDLE_COLUMN)) {
            $files[] = str_replace(base_path().'/', '', $path);
        }
    }

    sort($files);

    return $files;
}

// Seeders are skipped because a demo row is authored beside the fixture that
// reads it; tests, because a double is not a writer.
/**
 * @return list<string>
 */
function aliasNeedleWalk(string $directory): array
{
    $files = [];

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        if (is_link($path)) {
            continue;
        }

        if (is_dir($path)) {
            if (! in_array($entry, ['Migrations', 'Seeders', 'tests'], true)) {
                $files = array_merge($files, aliasNeedleWalk($path));
            }

            continue;
        }

        if (str_ends_with($entry, '.php')) {
            $files[] = $path;
        }
    }

    return $files;
}

// The verdict below is read off one walk, and a walk that opened nothing
// answers "every writer pays the floor" in the same words a correct tree does.
it('finds the writers it is about to judge', function (): void {
    expect(count(aliasNeedleWriterFiles()))->toBeGreaterThanOrEqual(
        4,
        'The scan found almost no writer of merchant_aliases.generalized_pattern. There are several; a '
        .'count this low means the walk stopped rather than that the tree stopped writing the column.',
    );
});

it('reads the floor at every writer of an alias needle', function (): void {
    $unguarded = array_values(array_filter(
        aliasNeedleWriterFiles(),
        static fn (string $relative): bool => ! str_contains(
            (string) file_get_contents(base_path($relative)),
            ALIAS_NEEDLE_SEAM,
        ),
    ));

    expect($unguarded)->toBe([], implode("\n  ", [
        'These write merchant_aliases.generalized_pattern without reading '.ALIAS_NEEDLE_SEAM.'. The needle',
        'matches as a whole token against every description the reader owns, so anything under '
        .AliasMatchPreviewQuery::MIN_PATTERN_LENGTH.' characters',
        'renames their ledger. Resolve the value through the seam — orRefuse() for one the reader typed,',
        'isBelowFloor() where a screen has its own wording for the refusal:',
        ...$unguarded,
    ]));
});
